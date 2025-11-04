<?php

namespace App\Repository;

use App\Database\DatabaseConnection;
use App\Database\SchedulerQueries;
use App\Entity\ScheduledTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    private DatabaseConnection $db;

    public function __construct(
        ManagerRegistry $registry,
        DatabaseConnection $db
    ) {
        parent::__construct($registry, ScheduledTask::class);
        $this->db = $db;
    }

    /**
     * Acquire a database lock using the appropriate method for the current database.
     *
     * @param string $lockName Lock identifier
     * @param int $timeout Timeout in seconds (0 = non-blocking)
     * @return bool True if lock was acquired
     */
    private function acquireLock(string $lockName, int $timeout = 0): bool
    {
        $platform = $this->db->getPlatform();

        if ($platform === 'postgresql') {
            // PostgreSQL: Use advisory locks (integer hash key)
            $lockKey = crc32($lockName);
            return (bool) $this->db->fetchOne(SchedulerQueries::LOCK_ACQUIRE_POSTGRESQL, [$lockKey]);
        } else {
            // MySQL/MariaDB: Use GET_LOCK with named locks
            return (bool) $this->db->fetchOne(SchedulerQueries::LOCK_ACQUIRE_MYSQL, [$lockName, $timeout]);
        }
    }

    /**
     * Release a database lock using the appropriate method for the current database.
     *
     * @param string $lockName Lock identifier
     * @return bool True if lock was released
     */
    private function releaseLock(string $lockName): bool
    {
        $platform = $this->db->getPlatform();

        if ($platform === 'postgresql') {
            // PostgreSQL: Release advisory lock
            $lockKey = crc32($lockName);
            return (bool) $this->db->fetchOne(SchedulerQueries::LOCK_RELEASE_POSTGRESQL, [$lockKey]);
        } else {
            // MySQL/MariaDB: Release named lock
            return (bool) $this->db->fetchOne(SchedulerQueries::LOCK_RELEASE_MYSQL, [$lockName]);
        }
    }

    /**
     * Distributes pending tasks fairly among workers.
     *
     * Strategy:
     * 1. Count total pending tasks
     * 2. Calculate tasks per worker (total / workers)
     * 3. Use OFFSET and LIMIT to assign different slices to each worker
     * 4. Use DB lock to ensure atomic assignment
     *
     * @param int $workerId Worker identifier (0-based: 0,1,2,3,4)
     * @param int $totalWorkers Total number of workers
     * @return array Tasks assigned to this worker
     */
    public function assignTasksFairly(int $workerId, int $totalWorkers): array
    {
        if ($workerId < 0 || $workerId >= $totalWorkers) {
            throw new \InvalidArgumentException("Invalid worker_id: must be between 0 and " . ($totalWorkers - 1));
        }

        // Acquire database lock for this worker
        $lockName = 'scheduler_worker_' . $workerId;

        if (!$this->acquireLock($lockName, 0)) {
            // Could not acquire lock - another instance of this worker is running
            return [];
        }

        try {
            // STEP 1: Count pending tasks
            $totalPending = (int) $this->db->fetchOne(
                SchedulerQueries::COUNT_PENDING_TASKS,
                ['pending' => SchedulerQueries::getStatusPending()]
            );

            if ($totalPending === 0) {
                return [];
            }

            // STEP 2: Calculate distribution
            $tasksPerWorker = (int) floor($totalPending / $totalWorkers);
            $remainder = $totalPending % $totalWorkers;

            // First 'remainder' workers (0, 1, ..., remainder-1) get one extra task
            $myTaskCount = $tasksPerWorker + ($workerId < $remainder ? 1 : 0);

            // Calculate offset for this worker
            $offset = 0;
            for ($i = 0; $i < $workerId; $i++) {
                $offset += $tasksPerWorker + ($i < $remainder ? 1 : 0);
            }

            if ($myTaskCount === 0) {
                return [];
            }

            // STEP 3: Assign tasks using UPDATE with ORDER BY + LIMIT + subquery
            $this->db->execute(SchedulerQueries::ASSIGN_TASKS_TO_WORKER, [
                'processing' => SchedulerQueries::getStatusProcessing(),
                'pending' => SchedulerQueries::getStatusPending(),
                'worker_id' => $workerId,
                'limit' => $myTaskCount,
                'offset' => $offset
            ]);

            // STEP 4: Fetch assigned tasks
            // Calculate the time threshold in PHP for database compatibility
            $tenSecondsAgo = (new \DateTime())->modify('-10 seconds')->format('Y-m-d H:i:s');

            return $this->db->fetchAll(SchedulerQueries::FETCH_ASSIGNED_TASKS, [
                'worker_id' => $workerId,
                'processing' => SchedulerQueries::getStatusProcessing(),
                'time_threshold' => $tenSecondsAgo
            ]);

        } finally {
            // Always release the lock
            $this->releaseLock($lockName);
        }
    }

    /**
     * Fetch and lock tasks ready for processing using FOR UPDATE SKIP LOCKED.
     * This ensures multiple workers can run concurrently without conflicts.
     *
     * NOTE: FOR UPDATE SKIP LOCKED requires:
     *  - PostgreSQL 9.5+
     *  - MySQL 8.0+
     *  - MariaDB 10.6+
     *
     * For older database versions or better worker distribution, use assignTasksFairly() instead.
     *
     * @param int $limit Maximum number of tasks to fetch
     * @return array Array of task data (not entities, for performance)
     */
    public function fetchAndLockPendingTasks(int $limit = 100): array
    {
        $this->db->beginTransaction();

        try {
            // Step 1: SELECT tasks with lock
            $stmt = $this->db->prepare(SchedulerQueries::SELECT_TASKS_FOR_LOCKING);
            $stmt->bindValue('status', SchedulerQueries::getStatusPending());
            $stmt->bindValue('limit', $limit);
            $result = $stmt->executeQuery();

            $tasks = $result->fetchAllAssociative();

            if (empty($tasks)) {
                $this->db->commit();
                return [];
            }

            $ids = array_column($tasks, 'id');

            // Step 2: Mark as processing IMMEDIATELY
            // Build IN clause for multiple IDs
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = str_replace('(:ids)', "($placeholders)", SchedulerQueries::MARK_TASKS_AS_PROCESSING);

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(1, SchedulerQueries::getStatusProcessing());

            // Bind each ID
            foreach ($ids as $index => $id) {
                $stmt->bindValue($index + 2, $id);
            }

            $stmt->executeStatement();

            $this->db->commit();

            return $tasks;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reset tasks that are stuck in "processing" status for too long.
     * This handles cases where workers died or crashed.
     *
     * @param int $timeoutMinutes Time in minutes before considering a task stuck
     * @return int Number of tasks reset
     */
    public function resetStuckTasks(int $timeoutMinutes = 5): int
    {
        $timeoutDate = (new \DateTime("-{$timeoutMinutes} minutes"))->format('Y-m-d H:i:s');

        return $this->db->execute(SchedulerQueries::RESET_STUCK_TASKS, [
            'pending' => SchedulerQueries::getStatusPending(),
            'processing' => SchedulerQueries::getStatusProcessing(),
            'timeout' => $timeoutDate
        ]);
    }

    /**
     * Get statistics about scheduled tasks.
     *
     * @return array Statistics by status
     */
    public function getStatistics(): array
    {
        $results = $this->db->fetchAll(SchedulerQueries::GET_STATISTICS);

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['status']] = [
                'count' => (int) $row['count'],
                'oldest_scheduled' => $row['oldest_scheduled'],
                'newest_scheduled' => $row['newest_scheduled'],
            ];
        }

        return $stats;
    }

    /**
     * Get overdue tasks count (pending tasks that should have run already).
     *
     * @return int Number of overdue tasks
     */
    public function getOverdueCount(): int
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        return (int) $this->db->fetchOne(SchedulerQueries::COUNT_OVERDUE_TASKS, [
            'now' => $now,
            'pending' => SchedulerQueries::getStatusPending()
        ]);
    }

    /**
     * Clean up old completed and failed tasks.
     *
     * @param int $daysOld Delete tasks older than this many days
     * @return int Number of tasks deleted
     */
    public function cleanupOldTasks(int $daysOld = 30): int
    {
        $threshold = (new \DateTime("-{$daysOld} days"))->format('Y-m-d H:i:s');

        return $this->db->execute(SchedulerQueries::DELETE_OLD_TASKS, [
            'completed' => SchedulerQueries::getStatusCompleted(),
            'failed' => SchedulerQueries::getStatusFailed(),
            'threshold' => $threshold
        ]);
    }

    /**
     * Mark a task as completed.
     *
     * @param int $taskId
     * @return int Number of rows affected
     */
    public function markTaskCompleted(int $taskId): int
    {
        return $this->db->execute(SchedulerQueries::MARK_TASK_COMPLETED, [
            'completed' => SchedulerQueries::getStatusCompleted(),
            'id' => $taskId
        ]);
    }

    /**
     * Mark a task as failed with error message.
     *
     * @param int $taskId
     * @param string $error
     * @return int Number of rows affected
     */
    public function markTaskFailed(int $taskId, string $error): int
    {
        return $this->db->execute(SchedulerQueries::MARK_TASK_FAILED, [
            'failed' => SchedulerQueries::getStatusFailed(),
            'id' => $taskId,
            'error' => substr($error, 0, 5000) // Limit error length
        ]);
    }

    /**
     * Reset a task to pending status for retry.
     *
     * @param int $taskId
     * @param string $error
     * @return int Number of rows affected
     */
    public function resetTaskForRetry(int $taskId, string $error): int
    {
        return $this->db->execute(SchedulerQueries::RESET_TASK_FOR_RETRY, [
            'pending' => SchedulerQueries::getStatusPending(),
            'id' => $taskId,
            'error' => substr($error, 0, 5000) // Limit error length
        ]);
    }
}
