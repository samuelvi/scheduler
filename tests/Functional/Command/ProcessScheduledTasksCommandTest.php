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
            '--limit' => 10,
            '--max-execution-time' => 0,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker finished', $output);
        $this->assertStringContainsString('Processing 10 tasks', $output);
    }

    public function testCommandWithCustomLimit(): void
    {
        // Create 20 tasks
        for ($i = 0; $i < 20; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['message' => "Test {$i}"]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Process only 5 tasks
        $this->commandTester->execute([
            '--limit' => 5,
            '--max-execution-time' => 0,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Processing 5 tasks', $output);
        $this->assertStringContainsString('Processed 5/5 tasks', $output);
    }

    public function testCommandRespectsMaxExecutionTime(): void
    {
        // Create tasks
        for ($i = 0; $i < 5; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['message' => "Test {$i}"]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Execute with very short max execution time
        $this->commandTester->execute([
            '--max-execution-time' => 1,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testCommandWithNoTasks(): void
    {
        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No tasks to process', $output);
    }

    public function testCommandProcessesUpToLimit(): void
    {
        // Create 20 tasks
        for ($i = 0; $i < 20; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['user_id' => 1, 'message' => 'Test notification']);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Process only 10 tasks with limit and terminate after first iteration
        $this->commandTester->execute([
            '--limit' => 10,
            '--max-execution-time' => 0, // Exit immediately after first iteration
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();

        // Should process exactly 10 tasks
        $this->assertStringContainsString('Processing 10 tasks', $output);
        $this->assertStringContainsString('Processed 10/10 tasks', $output);

        // Clear entity manager to get fresh data from DB
        $this->entityManager->clear();

        // Verify 10 tasks were completed and 10 remain pending
        $completedCount = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status = ?',
            [ScheduledTask::STATUS_COMPLETED]
        )->fetchOne();

        $pendingCount = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status = ?',
            [ScheduledTask::STATUS_PENDING]
        )->fetchOne();

        $this->assertEquals(10, $completedCount, 'Should have 10 completed tasks');
        $this->assertEquals(10, $pendingCount, 'Should have 10 pending tasks');
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

        $this->commandTester->execute([]);

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
