<?php

namespace App\Tests\Unit\Service;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use App\Service\TaskProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskProcessorTest extends TestCase
{
    private ScheduledTaskRepository $taskRepository;
    private LoggerInterface $logger;
    private TaskProcessor $processor;

    protected function setUp(): void
    {
        $this->taskRepository = $this->createMock(ScheduledTaskRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->processor = new TaskProcessor($this->taskRepository, $this->logger);
    }

    public function testProcessSuccessfulTask(): void
    {
        $taskData = [
            'id' => 1,
            'use_case' => 'send_email',
            'payload' => json_encode(['to' => 'test@example.com', 'subject' => 'Test']),
            'scheduled_at' => '2025-01-03 10:00:00',
            'attempts' => 1,
            'max_attempts' => 3
        ];

        $this->taskRepository
            ->expects($this->once())
            ->method('markTaskCompleted')
            ->with(1);

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

        $this->taskRepository
            ->expects($this->once())
            ->method('resetTaskForRetry')
            ->with(1, $this->stringContains('Unknown use case'));

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

        $this->taskRepository
            ->expects($this->once())
            ->method('resetTaskForRetry');

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

        $this->taskRepository
            ->expects($this->once())
            ->method('markTaskFailed')
            ->with(1, $this->stringContains('Unknown use case'));

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
            'attempts' => 1,
            'max_attempts' => 3
        ];

        $this->taskRepository
            ->expects($this->once())
            ->method('markTaskCompleted')
            ->with(1);

        $this->processor->process($taskData);
    }
}
