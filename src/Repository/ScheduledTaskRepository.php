<?php

namespace App\Repository;

use App\Entity\ScheduledTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    /**
     * Detect the database platform being used.
     *
     * @return string 'postgresql', 'mysql', or 'mariadb'
     */
    private function getDatabasePlatform(): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform()->getName();

        return match (true) {
            str_contains($platform, 'postgres') => 'postgresql',
            str_contains($platform, 'mysql') => 'mysql',
            default => 'mysql', // Fallback to MySQL/MariaDB
        };
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
        $conn = $this->getEntityManager()->getConnection();
        $platform = $this->getDatabasePlatform();

        if ($platform === 'postgresql') {
            // PostgreSQL: Use advisory locks (integer hash key)
            $lockKey = crc32($lockName);
            return (bool) $conn->executeQuery(
                "SELECT pg_try_advisory_lock(?)",
                [$lockKey]
            )->fetchOne();
        } else {
            // MySQL/MariaDB: Use GET_LOCK with named locks
            return (bool) $conn->executeQuery(
                "SELECT GET_LOCK(?, ?)",
                [$lockName, $timeout]
            )->fetchOne();
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
        $conn = $this->getEntityManager()->getConnection();
        $platform = $this->getDatabasePlatform();

        if ($platform === 'postgresql') {
            // PostgreSQL: Release advisory lock
            $lockKey = crc32($lockName);
            return (bool) $conn->executeQuery(
                "SELECT pg_advisory_unlock(?)",
                [$lockKey]
            )->fetchOne();
        } else {
            // MySQL/MariaDB: Release named lock
            return (bool) $conn->executeQuery(
                "SELECT RELEASE_LOCK(?)",
                [$lockName]
            )->fetchOne();
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
        // Automatically uses the appropriate locking method (PostgreSQL advisory locks or MySQL/MariaDB GET_LOCK)
        $lockName = 'scheduler_worker_' . $workerId;

        if (!$this->acquireLock($lockName, 0)) {
            // Could not acquire lock - another instance of this worker is running
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        try {
            // STEP 1: Count pending tasks
            $totalPending = (int) $conn->executeQuery("
                SELECT COUNT(*)
                FROM scheduled_tasks
                WHERE scheduled_at <= NOW()
                  AND status = :pending
                  AND attempts < max_attempts
            ", [
                'pending' => ScheduledTask::STATUS_PENDING
            ])->fetchOne();

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
            $conn->executeStatement("
                UPDATE scheduled_tasks
                SET status = :processing,
                    worker_id = :worker_id,
                    attempts = attempts + 1,
                    updated_at = NOW()
                WHERE id IN (
                    SELECT id FROM (
                        SELECT id
                        FROM scheduled_tasks
                        WHERE scheduled_at <= NOW()
                          AND status = :pending
                          AND attempts < max_attempts
                        ORDER BY scheduled_at ASC, id ASC
                        LIMIT :limit OFFSET :offset
                    ) AS subquery
                )
            ", [
                'processing' => ScheduledTask::STATUS_PROCESSING,
                'pending' => ScheduledTask::STATUS_PENDING,
                'worker_id' => $workerId,
                'limit' => $myTaskCount,
                'offset' => $offset
            ]);

            // STEP 4: Fetch assigned tasks
            // Calculate the time threshold in PHP for database compatibility
            $tenSecondsAgo = (new \DateTime())->modify('-10 seconds')->format('Y-m-d H:i:s');

            return $conn->executeQuery("
                SELECT *
                FROM scheduled_tasks
                WHERE worker_id = :worker_id
                  AND status = :processing
                  AND updated_at >= :time_threshold
                ORDER BY scheduled_at ASC
            ", [
                'worker_id' => $workerId,
                'processing' => ScheduledTask::STATUS_PROCESSING,
                'time_threshold' => $tenSecondsAgo
            ])->fetchAllAssociative();

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
        $conn = $this->getEntityManager()->getConnection();

        $conn->beginTransaction();

        try {
            // Step 1: SELECT tasks with lock
            $sql = "
                SELECT *
                FROM scheduled_tasks
                WHERE scheduled_at <= NOW()
                  AND status = :status
                  AND attempts < max_attempts
                ORDER BY scheduled_at ASC, id ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue('status', ScheduledTask::STATUS_PENDING);
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);

            $tasks = $stmt->executeQuery()->fetchAllAssociative();

            if (empty($tasks)) {
                $conn->commit();
                return [];
            }

            $ids = array_column($tasks, 'id');

            // Step 2: Mark as processing IMMEDIATELY
            $conn->executeStatement(
                "UPDATE scheduled_tasks
                 SET status = :processing,
                     attempts = attempts + 1,
                     updated_at = NOW()
                 WHERE id IN (:ids)",
                [
                    'processing' => ScheduledTask::STATUS_PROCESSING,
                    'ids' => $ids
                ],
                [
                    'ids' => Connection::PARAM_INT_ARRAY
                ]
            );

            $conn->commit();

            return $tasks;

        } catch (\Exception $e) {
            $conn->rollBack();
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
        $qb = $this->createQueryBuilder('t');

        return $qb->update()
            ->set('t.status', ':pending')
            ->where('t.status = :processing')
            ->andWhere('t.updatedAt < :timeout')
            ->andWhere('t.attempts < t.maxAttempts')
            ->setParameter('pending', ScheduledTask::STATUS_PENDING)
            ->setParameter('processing', ScheduledTask::STATUS_PROCESSING)
            ->setParameter('timeout', new \DateTime("-{$timeoutMinutes} minutes"))
            ->getQuery()
            ->execute();
    }

    /**
     * Get statistics about scheduled tasks.
     *
     * @return array Statistics by status
     */
    public function getStatistics(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT
                status,
                COUNT(*) as count,
                MIN(scheduled_at) as oldest_scheduled,
                MAX(scheduled_at) as newest_scheduled
            FROM scheduled_tasks
            GROUP BY status
        ";

        $results = $conn->executeQuery($sql)->fetchAllAssociative();

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
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.scheduledAt <= :now')
            ->andWhere('t.status = :pending')
            ->setParameter('now', new \DateTime())
            ->setParameter('pending', ScheduledTask::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clean up old completed and failed tasks.
     *
     * @param int $daysOld Delete tasks older than this many days
     * @return int Number of tasks deleted
     */
    public function cleanupOldTasks(int $daysOld = 30): int
    {
        $qb = $this->createQueryBuilder('t');

        return $qb->delete()
            ->where('t.status IN (:statuses)')
            ->andWhere('t.processedAt < :threshold')
            ->setParameter('statuses', [
                ScheduledTask::STATUS_COMPLETED,
                ScheduledTask::STATUS_FAILED
            ])
            ->setParameter('threshold', new \DateTime("-{$daysOld} days"))
            ->getQuery()
            ->execute();
    }
}
