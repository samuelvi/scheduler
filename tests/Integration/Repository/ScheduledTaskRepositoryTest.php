<?php

namespace App\Tests\Integration\Repository;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduledTaskRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ScheduledTaskRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = $this->entityManager->getRepository(ScheduledTask::class);

        // Clean database before each test
        $this->entityManager->getConnection()->executeStatement('DELETE FROM scheduled_tasks');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }

    public function testAssignTasksFairlyWithEvenDistribution(): void
    {
        // Create 100 tasks
        for ($i = 0; $i < 100; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Distribute among 5 workers
        $tasksPerWorker = [];
        for ($workerId = 1; $workerId <= 5; $workerId++) {
            $tasks = $this->repository->assignTasksFairly($workerId, 5);
            $tasksPerWorker[$workerId] = count($tasks);
        }

        // Each worker should get exactly 20 tasks
        $this->assertEquals(20, $tasksPerWorker[1]);
        $this->assertEquals(20, $tasksPerWorker[2]);
        $this->assertEquals(20, $tasksPerWorker[3]);
        $this->assertEquals(20, $tasksPerWorker[4]);
        $this->assertEquals(20, $tasksPerWorker[5]);

        // Verify all tasks were assigned
        $this->assertDatabaseTaskCount(100, ScheduledTask::STATUS_PROCESSING);
    }

    public function testAssignTasksFairlyWithUnevenDistribution(): void
    {
        // Create 537 tasks (uneven number)
        for ($i = 0; $i < 537; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Distribute among 5 workers
        $tasksPerWorker = [];
        for ($workerId = 1; $workerId <= 5; $workerId++) {
            $tasks = $this->repository->assignTasksFairly($workerId, 5);
            $tasksPerWorker[$workerId] = count($tasks);
        }

        // 537 / 5 = 107 remainder 2
        // First 2 workers get 108, rest get 107
        $this->assertEquals(108, $tasksPerWorker[1]);
        $this->assertEquals(108, $tasksPerWorker[2]);
        $this->assertEquals(107, $tasksPerWorker[3]);
        $this->assertEquals(107, $tasksPerWorker[4]);
        $this->assertEquals(107, $tasksPerWorker[5]);

        // Verify total
        $total = array_sum($tasksPerWorker);
        $this->assertEquals(537, $total);
    }

    public function testAssignTasksFairlyWithNoTasks(): void
    {
        // No tasks in database
        $tasks = $this->repository->assignTasksFairly(1, 5);

        $this->assertEmpty($tasks);
    }

    public function testAssignTasksFairlyOnlyPendingTasks(): void
    {
        // Create 50 pending and 50 processing tasks
        for ($i = 0; $i < 50; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }

        for ($i = 50; $i < 100; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $task->setStatus(ScheduledTask::STATUS_PROCESSING);
            $this->entityManager->persist($task);
        }

        $this->entityManager->flush();

        // Should only get pending tasks
        $tasks = $this->repository->assignTasksFairly(1, 5);

        // 50 pending / 5 workers = 10 each
        $this->assertEquals(10, count($tasks));
    }

    public function testAssignTasksFairlyOnlyDueTasks(): void
    {
        // Create 25 tasks due now
        for ($i = 0; $i < 25; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime('-1 minute'));
            $this->entityManager->persist($task);
        }

        // Create 25 tasks due in future
        for ($i = 25; $i < 50; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime('+1 hour'));
            $this->entityManager->persist($task);
        }

        $this->entityManager->flush();

        // Should only get due tasks
        $tasks = $this->repository->assignTasksFairly(1, 5);

        // 25 due tasks / 5 workers = 5 each
        $this->assertEquals(5, count($tasks));
    }

    /**
     * Edge case: Single task
     */
    public function testAssignTasksFairlyWithSingleTask(): void
    {
        $task = new ScheduledTask();
        $task->setUseCase('test_case');
        $task->setPayload(['test' => 'data']);
        $task->setScheduledAt(new \DateTime());
        $this->entityManager->persist($task);
        $this->entityManager->flush();

        // Worker 1 should get the task
        $tasksWorker1 = $this->repository->assignTasksFairly(1, 5);
        $this->assertCount(1, $tasksWorker1);

        // Workers 2-5 should get nothing
        $tasksWorker2 = $this->repository->assignTasksFairly(2, 5);
        $this->assertCount(0, $tasksWorker2);
    }

    /**
     * Edge case: More workers than tasks
     */
    public function testAssignTasksFairlyMoreWorkersThanTasks(): void
    {
        // Create 3 tasks
        for ($i = 0; $i < 3; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Try to distribute among 10 workers
        $counts = [];
        for ($workerId = 1; $workerId <= 10; $workerId++) {
            $tasks = $this->repository->assignTasksFairly($workerId, 10);
            $counts[$workerId] = count($tasks);
        }

        // First 3 workers get 1 task each, rest get 0
        $this->assertEquals(1, $counts[1]);
        $this->assertEquals(1, $counts[2]);
        $this->assertEquals(1, $counts[3]);
        $this->assertEquals(0, $counts[4]);
        $this->assertEquals(0, $counts[10]);
    }

    /**
     * Edge case: Tasks with max attempts reached should not be assigned
     */
    public function testAssignTasksFairlyExcludesMaxAttemptsReached(): void
    {
        // Create 50 tasks with attempts < max_attempts
        for ($i = 0; $i < 50; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $task->setAttempts(2);
            $task->setMaxAttempts(3);
            $this->entityManager->persist($task);
        }

        // Create 50 tasks with attempts >= max_attempts
        for ($i = 50; $i < 100; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $task->setAttempts(3);
            $task->setMaxAttempts(3);
            $this->entityManager->persist($task);
        }

        $this->entityManager->flush();

        // Should only get tasks with attempts < max_attempts
        $tasks = $this->repository->assignTasksFairly(1, 5);

        // 50 eligible tasks / 5 workers = 10 each
        $this->assertEquals(10, count($tasks));
    }

    public function testResetStuckTasks(): void
    {
        // Create stuck task (processing for 10 minutes)
        $stuckTask = new ScheduledTask();
        $stuckTask->setUseCase('test_case');
        $stuckTask->setPayload(['test' => 'stuck']);
        $stuckTask->setScheduledAt(new \DateTime('-1 hour'));
        $stuckTask->setStatus(ScheduledTask::STATUS_PROCESSING);
        $stuckTask->setAttempts(1);
        $stuckTask->setUpdatedAt(new \DateTime('-10 minutes'));
        $this->entityManager->persist($stuckTask);

        // Create recently processing task (should not be reset)
        $recentTask = new ScheduledTask();
        $recentTask->setUseCase('test_case');
        $recentTask->setPayload(['test' => 'recent']);
        $recentTask->setScheduledAt(new \DateTime('-1 hour'));
        $recentTask->setStatus(ScheduledTask::STATUS_PROCESSING);
        $recentTask->setAttempts(1);
        $this->entityManager->persist($recentTask);

        $this->entityManager->flush();

        $resetCount = $this->repository->resetStuckTasks(5);

        $this->assertEquals(1, $resetCount);
    }

    public function testGetStatistics(): void
    {
        // Create tasks with different statuses
        $statuses = [
            ScheduledTask::STATUS_PENDING => 10,
            ScheduledTask::STATUS_PROCESSING => 5,
            ScheduledTask::STATUS_COMPLETED => 20,
            ScheduledTask::STATUS_FAILED => 3,
        ];

        foreach ($statuses as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                $task = new ScheduledTask();
                $task->setUseCase('test_case');
                $task->setPayload(['index' => $i]);
                $task->setScheduledAt(new \DateTime());
                $task->setStatus($status);
                $this->entityManager->persist($task);
            }
        }

        $this->entityManager->flush();

        $stats = $this->repository->getStatistics();

        $this->assertEquals(10, $stats[ScheduledTask::STATUS_PENDING]['count']);
        $this->assertEquals(5, $stats[ScheduledTask::STATUS_PROCESSING]['count']);
        $this->assertEquals(20, $stats[ScheduledTask::STATUS_COMPLETED]['count']);
        $this->assertEquals(3, $stats[ScheduledTask::STATUS_FAILED]['count']);
    }

    public function testGetOverdueCount(): void
    {
        // Create 15 overdue tasks
        for ($i = 0; $i < 15; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime('-10 minutes'));
            $this->entityManager->persist($task);
        }

        // Create 10 future tasks
        for ($i = 15; $i < 25; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('test_case');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime('+1 hour'));
            $this->entityManager->persist($task);
        }

        $this->entityManager->flush();

        $overdueCount = $this->repository->getOverdueCount();

        $this->assertEquals(15, $overdueCount);
    }

    private function assertDatabaseTaskCount(int $expected, string $status): void
    {
        $count = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status = ?',
            [$status]
        )->fetchOne();

        $this->assertEquals($expected, $count);
    }
}
