<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ScheduledTask;
use PHPUnit\Framework\TestCase;

class ScheduledTaskTest extends TestCase
{
    public function testEntityCreation(): void
    {
        $task = new ScheduledTask();

        $this->assertNull($task->getId());
        $this->assertEquals(ScheduledTask::STATUS_PENDING, $task->getStatus());
        $this->assertEquals(0, $task->getAttempts());
        $this->assertEquals(3, $task->getMaxAttempts());
        $this->assertInstanceOf(\DateTimeInterface::class, $task->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $task->getUpdatedAt());
    }

    public function testSettersAndGetters(): void
    {
        $task = new ScheduledTask();
        $scheduledAt = new \DateTime('+1 hour');
        $payload = ['key' => 'value', 'number' => 123];

        $task->setUseCase('test_case');
        $task->setPayload($payload);
        $task->setScheduledAt($scheduledAt);
        $task->setWorkerId(5);
        $task->setMaxAttempts(5);

        $this->assertEquals('test_case', $task->getUseCase());
        $this->assertEquals($payload, $task->getPayload());
        $this->assertEquals($scheduledAt, $task->getScheduledAt());
        $this->assertEquals(5, $task->getWorkerId());
        $this->assertEquals(5, $task->getMaxAttempts());
    }

    public function testMarkAsProcessing(): void
    {
        $task = new ScheduledTask();
        $initialAttempts = $task->getAttempts();

        $task->markAsProcessing();

        $this->assertEquals(ScheduledTask::STATUS_PROCESSING, $task->getStatus());
        $this->assertEquals($initialAttempts + 1, $task->getAttempts());
    }

    public function testMarkAsCompleted(): void
    {
        $task = new ScheduledTask();
        $task->markAsCompleted();

        $this->assertEquals(ScheduledTask::STATUS_COMPLETED, $task->getStatus());
        $this->assertInstanceOf(\DateTimeInterface::class, $task->getProcessedAt());
    }

    public function testMarkAsFailed(): void
    {
        $task = new ScheduledTask();
        $errorMessage = 'Something went wrong';

        $task->markAsFailed($errorMessage);

        $this->assertEquals(ScheduledTask::STATUS_FAILED, $task->getStatus());
        $this->assertEquals($errorMessage, $task->getLastError());
        $this->assertInstanceOf(\DateTimeInterface::class, $task->getProcessedAt());
    }

    public function testCanRetry(): void
    {
        $task = new ScheduledTask();
        $task->setMaxAttempts(3);

        // Should be able to retry initially
        $this->assertTrue($task->canRetry());

        // After 2 attempts, should still be able to retry
        $task->setAttempts(2);
        $this->assertTrue($task->canRetry());

        // After 3 attempts (max), should not be able to retry
        $task->setAttempts(3);
        $this->assertFalse($task->canRetry());

        // After exceeding max attempts
        $task->setAttempts(4);
        $this->assertFalse($task->canRetry());
    }

    public function testIncrementAttempts(): void
    {
        $task = new ScheduledTask();

        $this->assertEquals(0, $task->getAttempts());

        $task->incrementAttempts();
        $this->assertEquals(1, $task->getAttempts());

        $task->incrementAttempts();
        $this->assertEquals(2, $task->getAttempts());
    }

    /**
     * Edge case: Multiple state transitions
     */
    public function testMultipleStateTransitions(): void
    {
        $task = new ScheduledTask();

        // pending -> processing
        $task->markAsProcessing();
        $this->assertEquals(ScheduledTask::STATUS_PROCESSING, $task->getStatus());
        $this->assertEquals(1, $task->getAttempts());

        // processing -> pending (retry scenario)
        $task->setStatus(ScheduledTask::STATUS_PENDING);
        $this->assertEquals(ScheduledTask::STATUS_PENDING, $task->getStatus());

        // pending -> processing (retry)
        $task->markAsProcessing();
        $this->assertEquals(ScheduledTask::STATUS_PROCESSING, $task->getStatus());
        $this->assertEquals(2, $task->getAttempts());

        // processing -> completed
        $task->markAsCompleted();
        $this->assertEquals(ScheduledTask::STATUS_COMPLETED, $task->getStatus());
    }

    /**
     * Edge case: Empty payload
     */
    public function testEmptyPayload(): void
    {
        $task = new ScheduledTask();
        $task->setPayload([]);

        $this->assertIsArray($task->getPayload());
        $this->assertEmpty($task->getPayload());
    }

    /**
     * Edge case: Complex nested payload
     */
    public function testComplexNestedPayload(): void
    {
        $task = new ScheduledTask();
        $complexPayload = [
            'user' => [
                'id' => 123,
                'email' => 'test@example.com',
                'preferences' => [
                    'notifications' => true,
                    'theme' => 'dark'
                ]
            ],
            'items' => [1, 2, 3, 4, 5],
            'metadata' => null
        ];

        $task->setPayload($complexPayload);

        $this->assertEquals($complexPayload, $task->getPayload());
        $this->assertEquals(123, $task->getPayload()['user']['id']);
        $this->assertNull($task->getPayload()['metadata']);
    }

    /**
     * Edge case: Worker ID can be null
     */
    public function testWorkerIdCanBeNull(): void
    {
        $task = new ScheduledTask();

        $this->assertNull($task->getWorkerId());

        $task->setWorkerId(1);
        $this->assertEquals(1, $task->getWorkerId());

        $task->setWorkerId(null);
        $this->assertNull($task->getWorkerId());
    }
}
