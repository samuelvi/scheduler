<?php

namespace App\Command;

use App\Entity\ScheduledTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:benchmark-scheduler',
    description: 'Benchmark scheduler performance with 1000 tasks',
)]
class BenchmarkSchedulerCommand extends Command
{
    private const USE_CASES = [
        'send_email' => 50,        // 50ms average
        'generate_report' => 200,  // 200ms average
        'process_payment' => 150,  // 150ms average
        'send_notification' => 30, // 30ms average
        'cleanup_data' => 100,     // 100ms average
    ];

    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tasks',
                't',
                InputOption::VALUE_REQUIRED,
                'Number of tasks to create',
                1000
            )
            ->addOption(
                'clean',
                'c',
                InputOption::VALUE_NONE,
                'Clean database before benchmark'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $taskCount = (int) $input->getOption('tasks');
        $clean = $input->getOption('clean');

        $io->title('Scheduler Performance Benchmark');

        if ($clean) {
            $io->section('Cleaning database');
            $this->entityManager->getConnection()->executeStatement('DELETE FROM scheduled_tasks');
            $io->success('Database cleaned');
        }

        // PHASE 1: Create tasks
        $io->section("Phase 1: Creating {$taskCount} tasks");
        $createStart = microtime(true);

        $tasksPerUseCase = (int) floor($taskCount / count(self::USE_CASES));
        $created = 0;

        foreach (self::USE_CASES as $useCase => $avgDuration) {
            for ($i = 0; $i < $tasksPerUseCase; $i++) {
                $task = new ScheduledTask();
                $task->setUseCase($useCase);
                $task->setPayload($this->generatePayload($useCase, $i));
                $task->setScheduledAt(new \DateTime());

                $this->entityManager->persist($task);
                $created++;

                // Flush every 100 tasks to avoid memory issues
                if ($created % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        $createDuration = microtime(true) - $createStart;

        $io->success(sprintf(
            'Created %d tasks in %.2f seconds (%.2f tasks/sec)',
            $created,
            $createDuration,
            $created / $createDuration
        ));

        // Show distribution
        $io->section('Task Distribution');
        $distribution = [];
        foreach (self::USE_CASES as $useCase => $avgDuration) {
            $count = (int) $this->entityManager->getConnection()->executeQuery(
                'SELECT COUNT(*) FROM scheduled_tasks WHERE use_case = ? AND status = ?',
                [$useCase, ScheduledTask::STATUS_PENDING]
            )->fetchOne();

            $distribution[] = [
                $useCase,
                $count,
                $avgDuration . 'ms',
                number_format($count * $avgDuration) . 'ms total'
            ];
        }

        $io->table(
            ['Use Case', 'Count', 'Avg Duration', 'Total Time'],
            $distribution
        );

        // Calculate theoretical times
        $totalTheoretical = array_sum(array_map(
            fn($uc, $dur) => $tasksPerUseCase * $dur,
            array_keys(self::USE_CASES),
            array_values(self::USE_CASES)
        ));

        $io->section('Theoretical Processing Time');
        $io->text([
            sprintf('Total processing time (sequential): %.2f seconds', $totalTheoretical / 1000),
            sprintf('With 5 workers (parallel): %.2f seconds', ($totalTheoretical / 1000) / 5),
            sprintf('With 10 workers (parallel): %.2f seconds', ($totalTheoretical / 1000) / 10),
        ]);

        // PHASE 2: Simulate worker assignment
        $io->section('Phase 2: Worker Assignment Test');
        $assignStart = microtime(true);

        $repo = $this->entityManager->getRepository(ScheduledTask::class);
        $assignedPerWorker = [];

        for ($workerId = 1; $workerId <= 5; $workerId++) {
            $tasks = $repo->assignTasksFairly($workerId, 5);
            $assignedPerWorker[$workerId] = count($tasks);

            $io->text(sprintf('Worker %d: assigned %d tasks', $workerId, count($tasks)));
        }

        $assignDuration = microtime(true) - $assignStart;

        $io->success(sprintf(
            'Assignment completed in %.3f seconds',
            $assignDuration
        ));

        // Verify distribution
        $io->section('Assignment Distribution Verification');
        $total = array_sum($assignedPerWorker);
        $avg = $total / 5;
        $variance = array_sum(array_map(fn($c) => pow($c - $avg, 2), $assignedPerWorker)) / 5;
        $stdDev = sqrt($variance);

        $io->table(
            ['Worker', 'Tasks', 'Deviation'],
            array_map(
                fn($id, $count) => [
                    "Worker {$id}",
                    $count,
                    sprintf('%+d', $count - $avg)
                ],
                array_keys($assignedPerWorker),
                $assignedPerWorker
            )
        );

        $io->text([
            sprintf('Total assigned: %d', $total),
            sprintf('Average per worker: %.2f', $avg),
            sprintf('Standard deviation: %.2f', $stdDev),
        ]);

        if ($stdDev < 1) {
            $io->success('✓ Excellent distribution (std dev < 1)');
        } elseif ($stdDev < 2) {
            $io->info('✓ Good distribution (std dev < 2)');
        } else {
            $io->warning('⚠ Uneven distribution (std dev >= 2)');
        }

        // PHASE 3: Instructions for actual processing test
        $io->section('Phase 3: Actual Processing Test');
        $io->text([
            'Tasks are now ready in the database. To test actual processing:',
            '',
            'Option A - Sequential (slower, for testing):',
            '  docker-compose exec php bin/console app:process-scheduled-tasks --worker-id=1 --total-workers=5',
            '  docker-compose exec php bin/console app:process-scheduled-tasks --worker-id=2 --total-workers=5',
            '  ... (repeat for workers 3, 4, 5)',
            '',
            'Option B - Parallel (realistic, use multiple terminals):',
            '  Terminal 1: docker-compose exec php bin/console app:process-scheduled-tasks --worker-id=1 --total-workers=5',
            '  Terminal 2: docker-compose exec php bin/console app:process-scheduled-tasks --worker-id=2 --total-workers=5',
            '  ... (5 terminals total)',
            '',
            'The benchmark will measure:',
            '  - Assignment time (already measured above)',
            '  - Processing time (measure when running workers)',
            '  - Throughput (tasks/second)',
            '  - Distribution fairness',
        ]);

        // Summary
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Tasks Created', $created],
                ['Creation Time', sprintf('%.2fs', $createDuration)],
                ['Creation Rate', sprintf('%.2f tasks/sec', $created / $createDuration)],
                ['Assignment Time', sprintf('%.3fs', $assignDuration)],
                ['Assignment Rate', sprintf('%.2f tasks/sec', $created / $assignDuration)],
                ['Distribution Std Dev', sprintf('%.2f', $stdDev)],
                ['Theoretical Sequential', sprintf('%.2fs', $totalTheoretical / 1000)],
                ['Theoretical Parallel (5 workers)', sprintf('%.2fs', ($totalTheoretical / 1000) / 5)],
            ]
        );

        return Command::SUCCESS;
    }

    private function generatePayload(string $useCase, int $index): array
    {
        return match ($useCase) {
            'send_email' => [
                'to' => "user{$index}@example.com",
                'subject' => "Test Email #{$index}",
                'body' => "This is a test email body for task {$index}"
            ],
            'generate_report' => [
                'report_type' => ['daily', 'weekly', 'monthly'][array_rand(['daily', 'weekly', 'monthly'])],
                'user_id' => rand(1000, 9999),
                'date_range' => '2025-01'
            ],
            'process_payment' => [
                'customer_id' => rand(1000, 9999),
                'amount' => rand(10, 1000) / 10,
                'currency' => 'USD'
            ],
            'send_notification' => [
                'user_id' => rand(1000, 9999),
                'message' => "Notification message #{$index}",
                'type' => 'info'
            ],
            'cleanup_data' => [
                'table' => ['logs', 'sessions', 'cache'][array_rand(['logs', 'sessions', 'cache'])],
                'older_than' => '30 days'
            ],
            default => []
        };
    }
}
