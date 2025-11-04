<?php

namespace App\Tests\Functional\Concurrency;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use App\Tests\DatabaseTestCase;
use Doctrine\DBAL\Connection;

/**
 * Real concurrency test with multiple workers processing simultaneously
 *
 * Scenario:
 * - 20,000 pending tasks (15,000 with same date, 5,000 with different dates)
 * - 3 workers processing in parallel with staggered delays
 * - Insertion of 10 tasks between workers
 * - Validation of correct distribution and absence of race conditions
 */
class ConcurrentWorkersTest extends DatabaseTestCase
{
    private ScheduledTaskRepository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->entityManager->getRepository(ScheduledTask::class);
        $this->connection = $this->entityManager->getConnection();

        // Fix PostgreSQL sequence for bulk inserts
        // SchemaTool doesn't always set the DEFAULT correctly
        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'postgresql') {
            $this->connection->executeStatement(
                "ALTER TABLE scheduled_tasks ALTER COLUMN id SET DEFAULT nextval('scheduled_tasks_id_seq')"
            );
        }
    }

    public function testConcurrentWorkersWithStaggeredInserts(): void
    {
        // =====================================
        // PHASE 1: Create 20,000 initial tasks
        // =====================================
        $this->createInitialTasks();

        // Verify initial state
        $initialCount = $this->getTaskCount('pending');
        $this->assertEquals(20000, $initialCount, 'Should have 20,000 initial pending tasks');

        // =====================================
        // PHASE 2: Simulate 3 workers processing
        // =====================================
        $results = $this->simulateThreeWorkersWithInserts();

        // =====================================
        // PHASE 3: Concurrency validations
        // =====================================
        $this->validateConcurrency($results);
        $this->validateDistribution($results);
        $this->validateNoRaceConditions($results);
        $this->validateRemainingTasks();
    }

    /**
     * Creates the 20,000 initial tasks with the specified pattern
     */
    private function createInitialTasks(): void
    {
        // Common date for 15,000 tasks
        $commonDate = new \DateTime('2025-11-04 10:00:00');

        // Base date for the other 5,000 (staggered)
        $baseDate = new \DateTime('2025-11-03 08:00:00');

        echo "\n📝 Creating 20,000 tasks...\n";
        $startTime = microtime(true);

        // Use direct SQL for better performance
        $batchSize = 500;
        $values = [];
        $created = 0;

        // 15,000 tasks with the same date (tie-broken by ID)
        for ($i = 0; $i < 15000; $i++) {
            $values[] = $this->createTaskValueSQL(
                'concurrent_test',
                ['index' => $i, 'batch' => 'same_date'],
                $commonDate
            );
            $created++;

            if (count($values) >= $batchSize) {
                $this->insertBatch($values);
                $values = [];
            }
        }

        // 5,000 tasks with different dates (distributed over time)
        for ($i = 0; $i < 5000; $i++) {
            // Distribute over a 2-hour range (approximately every 1.44 seconds)
            $taskDate = clone $baseDate;
            $taskDate->modify(sprintf('+%d seconds', $i * 2));

            $values[] = $this->createTaskValueSQL(
                'concurrent_test',
                ['index' => $i, 'batch' => 'different_dates'],
                $taskDate
            );
            $created++;

            if (count($values) >= $batchSize) {
                $this->insertBatch($values);
                $values = [];
            }
        }

        // Insert remaining
        if (!empty($values)) {
            $this->insertBatch($values);
        }

        $duration = microtime(true) - $startTime;
        echo sprintf("✅ Created %d tasks in %.2f seconds\n", $created, $duration);
    }

    /**
     * Simulates 3 workers processing with delays and intermediate insertions
     * Each worker processes in batches of 1000 until completing 5000 tasks
     */
    private function simulateThreeWorkersWithInserts(): array
    {
        echo "\n🔄 Starting simulation of 3 concurrent workers...\n";
        echo "   📦 Each worker will process in batches of 1,000 tasks until completing 5,000\n\n";

        $results = [
            'worker_1' => ['tasks' => [], 'start' => null, 'end' => null],
            'worker_2' => ['tasks' => [], 'start' => null, 'end' => null],
            'worker_3' => ['tasks' => [], 'start' => null, 'end' => null],
        ];

        // Worker 1: Process in batches of 1000 until 5000
        echo "🔸 Worker 1: Starting processing...\n";
        $results['worker_1'] = $this->simulateWorkerProcessing('Worker 1', 5000, 1000);

        // Insert 10 tasks between Worker 1 and Worker 2
        echo "\n📥 Inserting 10 new tasks (between Worker 1 and 2)...\n";
        $this->insertAdditionalTasks(10, 'insert_batch_1');

        // 2-second delay (sufficient to validate temporal order)
        echo "⏳ Waiting 2 seconds...\n\n";
        sleep(2);

        // Worker 2: Process in batches of 1000 until 5000
        echo "🔸 Worker 2: Starting processing...\n";
        $results['worker_2'] = $this->simulateWorkerProcessing('Worker 2', 5000, 1000);

        // Insert 10 tasks between Worker 2 and Worker 3
        echo "\n📥 Inserting 10 new tasks (between Worker 2 and 3)...\n";
        $this->insertAdditionalTasks(10, 'insert_batch_2');

        // 2-second delay (sufficient to validate temporal order)
        echo "⏳ Waiting 2 seconds...\n\n";
        sleep(2);

        // Worker 3: Process in batches of 1000 until 5000
        echo "🔸 Worker 3: Starting processing...\n";
        $results['worker_3'] = $this->simulateWorkerProcessing('Worker 3', 5000, 1000);

        return $results;
    }

    /**
     * Simulates a worker processing tasks in multiple batches
     *
     * @param string $workerName Worker name for logging
     * @param int $targetTotal Total tasks to process
     * @param int $batchSize Size of each batch
     * @return array ['tasks' => array, 'start' => float, 'end' => float]
     */
    private function simulateWorkerProcessing(string $workerName, int $targetTotal, int $batchSize): array
    {
        $allTasks = [];
        $start = microtime(true);
        $iteration = 1;

        while (count($allTasks) < $targetTotal) {
            $remaining = $targetTotal - count($allTasks);
            $limit = min($batchSize, $remaining);

            $batchStart = microtime(true);
            $tasks = $this->repository->fetchAndLockPendingTasks($limit);
            $batchDuration = microtime(true) - $batchStart;

            $allTasks = array_merge($allTasks, $tasks);

            echo sprintf(
                "   → Iteration %d: %d tasks in %.3f seconds (Total: %d/%d)\n",
                $iteration,
                count($tasks),
                $batchDuration,
                count($allTasks),
                $targetTotal
            );

            // If no more tasks obtained, finish
            if (empty($tasks)) {
                break;
            }

            $iteration++;
        }

        $end = microtime(true);

        echo sprintf(
            "   ✅ %s completed: %d tasks in %.3f total seconds\n",
            $workerName,
            count($allTasks),
            $end - $start
        );

        return [
            'tasks' => $allTasks,
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Validates that there are no duplicates (race conditions)
     */
    private function validateNoRaceConditions(array $results): void
    {
        echo "\n\n🔍 Validating absence of race conditions...\n";

        $allTaskIds = [];

        foreach ($results as $workerName => $data) {
            foreach ($data['tasks'] as $task) {
                $taskId = $task['id'];

                $this->assertArrayNotHasKey(
                    $taskId,
                    $allTaskIds,
                    sprintf(
                        "❌ RACE CONDITION DETECTED: Task ID %d processed by %s and %s",
                        $taskId,
                        $allTaskIds[$taskId] ?? 'unknown',
                        $workerName
                    )
                );

                $allTaskIds[$taskId] = $workerName;
            }
        }

        $uniqueCount = count($allTaskIds);
        $totalTasks = array_sum(array_map(fn($r) => count($r['tasks']), $results));

        $this->assertEquals(
            $totalTasks,
            $uniqueCount,
            sprintf(
                "❌ Total tasks (%d) doesn't match unique IDs (%d)",
                $totalTasks,
                $uniqueCount
            )
        );

        echo sprintf("✅ No race conditions: %d unique tasks processed\n", $uniqueCount);
    }

    /**
     * Validates correct distribution among workers
     */
    private function validateDistribution(array $results): void
    {
        echo "\n🎯 Validating task distribution...\n";

        $worker1Count = count($results['worker_1']['tasks']);
        $worker2Count = count($results['worker_2']['tasks']);
        $worker3Count = count($results['worker_3']['tasks']);

        echo sprintf("   Worker 1: %d tasks\n", $worker1Count);
        echo sprintf("   Worker 2: %d tasks\n", $worker2Count);
        echo sprintf("   Worker 3: %d tasks\n", $worker3Count);

        // Worker 1 should get exactly 5000 (there were 20,000 available)
        $this->assertEquals(5000, $worker1Count, 'Worker 1 should process 5,000 tasks');

        // Worker 2 should get exactly 5000 (15,000 remaining + 10 new = 15,010)
        $this->assertEquals(5000, $worker2Count, 'Worker 2 should process 5,000 tasks');

        // Worker 3 should get exactly 5000 (10,010 remaining + 10 new = 10,020)
        $this->assertEquals(5000, $worker3Count, 'Worker 3 should process 5,000 tasks');

        echo "✅ Correct distribution: each worker processed exactly 5,000 tasks\n";
    }

    /**
     * Validates remaining tasks for the next batch
     */
    private function validateRemainingTasks(): void
    {
        echo "\n📊 Validating remaining tasks...\n";

        // Expected calculation:
        // - Initial: 20,000 tasks
        // - Processed by 3 workers: 15,000 (5,000 each)
        // - Inserted: 20 (10 + 10)
        // - Remaining: 20,000 - 15,000 + 20 = 5,020
        $remainingPending = $this->getTaskCount('pending');

        echo sprintf("   Remaining pending tasks: %d\n", $remainingPending);

        $this->assertEquals(
            5020,
            $remainingPending,
            'Should have 5,020 pending tasks (5,000 unprocessed + 20 inserted)'
        );

        // Verify that the 20 inserted tasks are present
        $remainingTasks = $this->connection->executeQuery(
            "SELECT payload FROM scheduled_tasks WHERE status = 'pending' ORDER BY id"
        )->fetchAllAssociative();

        $batchCounts = ['insert_batch_1' => 0, 'insert_batch_2' => 0, 'same_date' => 0, 'different_dates' => 0];

        foreach ($remainingTasks as $task) {
            $payload = json_decode($task['payload'], true);
            $batch = $payload['batch'] ?? 'unknown';

            if (isset($batchCounts[$batch])) {
                $batchCounts[$batch]++;
            }
        }

        $this->assertEquals(10, $batchCounts['insert_batch_1'], 'Should have 10 tasks from first inserted batch');
        $this->assertEquals(10, $batchCounts['insert_batch_2'], 'Should have 10 tasks from second inserted batch');

        // The 5,000 remaining original tasks (those not processed)
        $originalRemaining = $batchCounts['same_date'] + $batchCounts['different_dates'];
        $this->assertEquals(5000, $originalRemaining, 'Should have 5,000 unprocessed original tasks');

        echo sprintf("   ✅ Unprocessed original tasks: %d\n", $originalRemaining);
        echo sprintf("   ✅ Tasks inserted during execution: %d (batch_1) + %d (batch_2)\n",
            $batchCounts['insert_batch_1'],
            $batchCounts['insert_batch_2']
        );
    }

    /**
     * Validates concurrency and processing order
     */
    private function validateConcurrency(array $results): void
    {
        echo "\n⏱️  Validating temporal concurrency...\n";

        // Worker 1 should finish before Worker 2 starts
        $this->assertLessThan(
            $results['worker_2']['start'],
            $results['worker_1']['end'],
            'Worker 1 should complete before Worker 2 starts'
        );

        // Worker 2 should finish before Worker 3 starts
        $this->assertLessThan(
            $results['worker_3']['start'],
            $results['worker_2']['end'],
            'Worker 2 should complete before Worker 3 starts'
        );

        echo "✅ Correct temporal order: Worker1 → Worker2 → Worker3\n";
    }

    // =====================================
    // HELPER METHODS
    // =====================================

    private function createTaskValueSQL(string $useCase, array $payload, \DateTime $scheduledAt): string
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        return sprintf(
            "(%s, %s, %s, 'pending', 0, 3, %s, %s)",
            $this->connection->quote($useCase),
            $this->connection->quote(json_encode($payload)),
            $this->connection->quote($scheduledAt->format('Y-m-d H:i:s')),
            $this->connection->quote($now),
            $this->connection->quote($now)
        );
    }

    private function insertBatch(array $values): void
    {
        $sql = sprintf(
            "INSERT INTO scheduled_tasks (use_case, payload, scheduled_at, status, attempts, max_attempts, created_at, updated_at) VALUES %s",
            implode(', ', $values)
        );
        $this->connection->executeStatement($sql);
    }

    private function insertAdditionalTasks(int $count, string $batchName): void
    {
        $now = new \DateTime();
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->createTaskValueSQL(
                'concurrent_test',
                ['batch' => $batchName, 'index' => $i],
                $now
            );
        }

        $this->insertBatch($values);
        echo sprintf("   ✓ Inserted %d tasks (%s)\n", $count, $batchName);
    }

    private function getTaskCount(string $status): int
    {
        return (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status = ?',
            [$status]
        )->fetchOne();
    }
}
