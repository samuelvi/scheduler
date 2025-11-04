<?php

namespace App\Tests\Functional\Command;

use App\Entity\ScheduledTask;
use App\Tests\DatabaseTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ProcessScheduledTasksCommandTest extends DatabaseTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $application = new Application(self::$kernel);
        $command = $application->find('app:process-scheduled-tasks');
        $this->commandTester = new CommandTester($command);
    }

    public function testCommandExecutesSuccessfully(): void
    {
        // Create 10 tasks
        for ($i = 0; $i < 10; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['message' => "Test {$i}"]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        $this->commandTester->execute([
            '--worker-id' => 1,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker 1 finished', $output);
    }

    public function testCommandWithInvalidWorkerId(): void
    {
        $this->commandTester->execute([
            '--worker-id' => -1,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker ID must be between 0 and 4', $output);
    }

    public function testCommandWithWorkerIdExceedingTotal(): void
    {
        $this->commandTester->execute([
            '--worker-id' => 10,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker ID must be between 0 and 4', $output);
    }

    public function testCommandWithNoTasks(): void
    {
        $this->commandTester->execute([
            '--worker-id' => 0,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No tasks to process', $output);
    }

    public function testCommandDistributesTasksFairly(): void
    {
        // Create 20 tasks (easier to verify)
        for ($i = 0; $i < 20; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['user_id' => 1, 'message' => 'Test notification']);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Run worker 0 with 5 total workers
        $this->commandTester->execute([
            '--worker-id' => 0,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Worker 0 should process 4 tasks (20 / 5 = 4 per worker)
        $this->assertStringContainsString('Processing 4 tasks', $output);
        $this->assertStringContainsString('Processed 4/4 tasks', $output);
    }

    /**
     * Edge case: Test with future tasks (should not process)
     */
    public function testCommandIgnoresFutureTasks(): void
    {
        // Create tasks scheduled for future
        for ($i = 0; $i < 10; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime('+1 hour'));
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        $this->commandTester->execute([
            '--worker-id' => 1,
            '--total-workers' => 5,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No tasks to process', $output);

        // Verify no tasks were processed
        $processedCount = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status != ?',
            [ScheduledTask::STATUS_PENDING]
        )->fetchOne();

        $this->assertEquals(0, $processedCount);
    }
}
