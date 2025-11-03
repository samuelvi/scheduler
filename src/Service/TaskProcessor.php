<?php

namespace App\Service;

use App\Entity\ScheduledTask;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * TaskProcessor handles the execution of scheduled tasks.
 *
 * Each use case should be handled in a dedicated handler.
 * This is the orchestrator that delegates to the appropriate handler.
 */
class TaskProcessor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Process a single task by delegating to the appropriate handler.
     *
     * @param array $taskData Task data from database (not an entity for performance)
     * @return void
     */
    public function process(array $taskData): void
    {
        $taskId = $taskData['id'];
        $useCase = $taskData['use_case'];
        $payload = json_decode($taskData['payload'], true);

        $this->logger->info('Processing task', [
            'task_id' => $taskId,
            'use_case' => $useCase,
            'scheduled_at' => $taskData['scheduled_at'],
            'attempt' => $taskData['attempts']
        ]);

        try {
            // Delegate to the appropriate handler based on use case
            $this->executeUseCase($useCase, $payload);

            // Mark as completed
            $this->markTaskAsCompleted($taskId);

            $this->logger->info('Task completed successfully', [
                'task_id' => $taskId,
                'use_case' => $useCase
            ]);

        } catch (\Exception $e) {
            // Check if we should retry or mark as failed
            $this->handleTaskFailure($taskId, $taskData['attempts'], $taskData['max_attempts'], $e);
        }
    }

    /**
     * Execute the specific use case logic.
     *
     * Each use case has a realistic duration to simulate real-world processing:
     * - send_email: 50ms (fast, simple operation)
     * - generate_report: 200ms (medium, needs computation)
     * - process_payment: 150ms (medium, external API call)
     * - send_notification: 30ms (very fast, push notification)
     * - cleanup_data: 100ms (fast, database operations)
     *
     * @param string $useCase The use case identifier
     * @param array $payload The task payload
     * @return void
     */
    private function executeUseCase(string $useCase, array $payload): void
    {
        match ($useCase) {
            'send_email' => $this->handleSendEmail($payload),
            'generate_report' => $this->handleGenerateReport($payload),
            'process_payment' => $this->handleProcessPayment($payload),
            'send_notification' => $this->handleSendNotification($payload),
            'cleanup_data' => $this->handleCleanupData($payload),
            default => throw new \InvalidArgumentException("Unknown use case: {$useCase}")
        };
    }

    /**
     * Use Case 1: Send Email (~50ms)
     * Simulates email sending via SMTP
     */
    private function handleSendEmail(array $payload): void
    {
        if (!isset($payload['to']) || !isset($payload['subject'])) {
            throw new \InvalidArgumentException('Missing required email fields: to, subject');
        }

        $this->logger->debug('Sending email', [
            'to' => $payload['to'],
            'subject' => $payload['subject']
        ]);

        // Simulate SMTP connection and sending (50ms)
        usleep(50000);

        // Simulate email validation
        if (!filter_var($payload['to'], FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("Invalid email address: {$payload['to']}");
        }

        // Success - email sent
    }

    /**
     * Use Case 2: Generate Report (~200ms)
     * Simulates complex data aggregation and PDF generation
     */
    private function handleGenerateReport(array $payload): void
    {
        if (!isset($payload['report_type'])) {
            throw new \InvalidArgumentException('Missing required field: report_type');
        }

        $this->logger->debug('Generating report', [
            'type' => $payload['report_type']
        ]);

        // Simulate database queries (100ms)
        usleep(100000);

        // Simulate PDF generation (100ms)
        usleep(100000);

        // Success - report generated
    }

    /**
     * Use Case 3: Process Payment (~150ms)
     * Simulates payment gateway API call
     */
    private function handleProcessPayment(array $payload): void
    {
        if (!isset($payload['amount']) || !isset($payload['customer_id'])) {
            throw new \InvalidArgumentException('Missing required fields: amount, customer_id');
        }

        $amount = $payload['amount'];
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }

        $this->logger->debug('Processing payment', [
            'customer_id' => $payload['customer_id'],
            'amount' => $amount
        ]);

        // Simulate payment gateway API call (150ms)
        usleep(150000);

        // Simulate random payment failures (5% failure rate)
        if (rand(1, 100) <= 5) {
            throw new \RuntimeException('Payment gateway error: Insufficient funds');
        }

        // Success - payment processed
    }

    /**
     * Use Case 4: Send Notification (~30ms)
     * Simulates push notification to mobile device
     */
    private function handleSendNotification(array $payload): void
    {
        if (!isset($payload['user_id']) || !isset($payload['message'])) {
            throw new \InvalidArgumentException('Missing required fields: user_id, message');
        }

        $this->logger->debug('Sending notification', [
            'user_id' => $payload['user_id'],
            'message' => substr($payload['message'], 0, 50)
        ]);

        // Simulate push notification service (30ms)
        usleep(30000);

        // Success - notification sent
    }

    /**
     * Use Case 5: Cleanup Data (~100ms)
     * Simulates database cleanup operations
     */
    private function handleCleanupData(array $payload): void
    {
        $this->logger->debug('Cleaning up data', $payload);

        // Simulate database queries (50ms)
        usleep(50000);

        // Simulate deletion operations (50ms)
        usleep(50000);

        // Success - cleanup completed
    }

    /**
     * Mark a task as completed in the database.
     */
    private function markTaskAsCompleted(int $taskId): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE scheduled_tasks
             SET status = :completed,
                 processed_at = NOW(),
                 updated_at = NOW()
             WHERE id = :id",
            [
                'completed' => ScheduledTask::STATUS_COMPLETED,
                'id' => $taskId
            ]
        );
    }

    /**
     * Handle task failure - either retry or mark as permanently failed.
     */
    private function handleTaskFailure(int $taskId, int $currentAttempts, int $maxAttempts, \Exception $e): void
    {
        $canRetry = $currentAttempts < $maxAttempts;

        if ($canRetry) {
            // Reset to pending for retry
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE scheduled_tasks
                 SET status = :pending,
                     last_error = :error,
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'pending' => ScheduledTask::STATUS_PENDING,
                    'error' => substr($e->getMessage(), 0, 5000), // Limit error message length
                    'id' => $taskId
                ]
            );

            $this->logger->error('Task failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->logger->warning('Task will be retried', [
                'task_id' => $taskId,
                'attempt' => $currentAttempts,
                'max_attempts' => $maxAttempts
            ]);
        } else {
            // Mark as permanently failed
            $this->entityManager->getConnection()->executeStatement(
                "UPDATE scheduled_tasks
                 SET status = :failed,
                     last_error = :error,
                     processed_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id",
                [
                    'failed' => ScheduledTask::STATUS_FAILED,
                    'error' => substr($e->getMessage(), 0, 5000),
                    'id' => $taskId
                ]
            );

            $this->logger->error('Task permanently failed after max attempts', [
                'task_id' => $taskId,
                'attempts' => $currentAttempts
            ]);
        }
    }
}
