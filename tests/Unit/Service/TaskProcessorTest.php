<?php

namespace App\Tests\Unit\Service;

use App\Entity\ScheduledTask;
use App\Service\TaskProcessor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskProcessorTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private LoggerInterface $logger;
    private TaskProcessor $processor;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->entityManager
            ->method('getConnection')
            ->willReturn($this->connection);

        $this->processor = new TaskProcessor($this->entityManager, $this->logger);
    }

    public function testProcessSuccessfulTask(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'send_email',
            'payload' => json_encode(['to' => 'test@example.com', 'subject' => 'Test']),
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 1
        ];

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE scheduled_tasks'),
                $this->callback(function ($params) {
                    return $params['completed'] === ScheduledTask::STATUS_COMPLETED
                        && $params['id'] === 1;
                })
            );

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->processor->process($taskData);
    }

    public function testProcessTaskWithInvalidUseCase(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'invalid_case',
            'payload' => json_encode([]),
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 1,
            'max_attempts' => 3
        ];

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Task failed',
                $this->callback(function ($context) {
                    return str_contains($context['error'], 'Unknown use case');
                })
            );

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                $this->callback(function ($params) {
                    return $params['pending'] === ScheduledTask::STATUS_PENDING;
                })
            );

        $this->processor->process($taskData);
    }

    /**
     * Edge case: Task with missing required payload fields
     */
    public function testProcessTaskWithMissingPayloadFields(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'send_email',
            'payload' => json_encode(['to' => 'test@example.com']), // missing 'subject'
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 1,
            'max_attempts' => 3
        ];

        $this->logger
            ->expects($this->once())
            ->method('error');

        $this->connection
            ->expects($this->once())
            ->method('executeStatement');

        $this->processor->process($taskData);
    }

    /**
     * Edge case: Task reaches max attempts and should be marked as failed
     */
    public function testProcessTaskExceedsMaxAttempts(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'invalid_case',
            'payload' => json_encode([]),
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 3,
            'max_attempts' => 3
        ];

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                $this->callback(function ($params) {
                    return $params['failed'] === ScheduledTask::STATUS_FAILED;
                })
            );

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                'Task permanently failed after max attempts',
                $this->anything()
            );

        $this->processor->process($taskData);
    }

    /**
     * Edge case: Empty payload
     */
    public function testProcessTaskWithEmptyPayload(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'cleanup_data',
            'payload' => json_encode([]),
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 1
        ];

        $this->connection
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('UPDATE'),
                $this->callback(function ($params) {
                    return $params['completed'] === ScheduledTask::STATUS_COMPLETED;
                })
            );

        $this->processor->process($taskData);
    }
}
