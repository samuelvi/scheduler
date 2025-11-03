<?php

namespace App\Tests\Functional\Command;

use App\Entity\ScheduledTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ProcessScheduledTasksCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $application = new Application(self::$kernel);
        $command = $application->find('app:process-scheduled-tasks');
        $this->commandTester = new CommandTester($command);

        // Clean database
        $this->entityManager->getConnection()->executeStatement('DELETE FROM scheduled_tasks');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
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
            '--worker-id' => 0,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker ID must be between 1 and 5', $output);
    }

    public function testCommandWithWorkerIdExceedingTotal(): void
    {
        $this->commandTester->execute([
            '--worker-id' => 10,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Worker ID must be between 1 and 5', $output);
    }

    public function testCommandWithNoTasks(): void
    {
        $this->commandTester->execute([
            '--worker-id' => 1,
            '--total-workers' => 5,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No tasks to process', $output);
    }

    public function testCommandDistributesTasksFairly(): void
    {
        // Create 100 tasks
        for ($i = 0; $i < 100; $i++) {
            $task = new ScheduledTask();
            $task->setUseCase('send_notification');
            $task->setPayload(['index' => $i]);
            $task->setScheduledAt(new \DateTime());
            $this->entityManager->persist($task);
        }
        $this->entityManager->flush();

        // Run 5 workers sequentially
        $processedPerWorker = [];
        for ($workerId = 1; $workerId <= 5; $workerId++) {
            $tester = new CommandTester(
                (new Application(self::$kernel))->find('app:process-scheduled-tasks')
            );

            $tester->execute([
                '--worker-id' => $workerId,
                '--total-workers' => 5,
            ]);

            // Count processed tasks
            $output = $tester->getDisplay();
            if (preg_match('/Processed (\d+)/', $output, $matches)) {
                $processedPerWorker[$workerId] = (int) $matches[1];
            }
        }

        // Each worker should process 20 tasks
        $this->assertEquals(20, $processedPerWorker[1]);
        $this->assertEquals(20, $processedPerWorker[2]);
        $this->assertEquals(20, $processedPerWorker[3]);
        $this->assertEquals(20, $processedPerWorker[4]);
        $this->assertEquals(20, $processedPerWorker[5]);

        // Verify all tasks are completed
        $completedCount = (int) $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM scheduled_tasks WHERE status = ?',
            [ScheduledTask::STATUS_COMPLETED]
        )->fetchOne();

        $this->assertEquals(100, $completedCount);
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
