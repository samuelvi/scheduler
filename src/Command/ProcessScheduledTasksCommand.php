<?php

namespace App\Command;

use App\Repository\ScheduledTaskRepository;
use App\Service\TaskProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:process-scheduled-tasks',
    description: 'Process scheduled tasks that are due for execution',
)]
class ProcessScheduledTasksCommand extends Command
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private TaskProcessor $taskProcessor,
        private LoggerInterface $logger,
        private ParameterBagInterface $params
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'worker-id',
                'w',
                InputOption::VALUE_REQUIRED,
                'Worker ID (0-based, e.g., 0, 1, 2, 3, 4)',
                0
            )
            ->addOption(
                'total-workers',
                null,
                InputOption::VALUE_REQUIRED,
                'Total number of workers for fair distribution',
                5
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run in daemon mode (continuous processing)'
            )
            ->addOption(
                'sleep',
                's',
                InputOption::VALUE_REQUIRED,
                'Sleep time in seconds between iterations (daemon mode)',
                10
            )
            ->addOption(
                'max-execution-time',
                't',
                InputOption::VALUE_REQUIRED,
                'Maximum execution time in seconds (for non-daemon mode)',
                $this->params->get('scheduler.max_execution_time')
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workerId = (int) $input->getOption('worker-id');
        $totalWorkers = (int) $input->getOption('total-workers');
        $isDaemon = $input->getOption('daemon');
        $sleepSeconds = (int) $input->getOption('sleep');
        $maxExecutionTime = (int) $input->getOption('max-execution-time');

        if ($workerId < 0 || $workerId >= $totalWorkers) {
            $io->error("Worker ID must be between 0 and " . ($totalWorkers - 1));
            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Starting worker %d/%d [daemon: %s, sleep: %ds]',
            $workerId,
            $totalWorkers,
            $isDaemon ? 'yes' : 'no',
            $sleepSeconds
        ));

        $this->logger->info('Scheduler worker started', [
            'worker_id' => $workerId,
            'total_workers' => $totalWorkers,
            'daemon' => $isDaemon
        ]);

        // First, reset any stuck tasks (only worker 0 does this)
        if ($workerId === 0) {
            $resetCount = $this->taskRepository->resetStuckTasks();
            if ($resetCount > 0) {
                $io->warning("Reset {$resetCount} stuck tasks");
                $this->logger->warning('Reset stuck tasks', ['count' => $resetCount]);
            }
        }

        $totalProcessed = 0;
        $iteration = 0;
        $startTime = time();

        do {
            $iteration++;

            try {
                // Fair distribution assignment
                $tasks = $this->taskRepository->assignTasksFairly($workerId, $totalWorkers);

                if (empty($tasks)) {
                    $io->text(sprintf('[Iteration %d] No tasks to process', $iteration));

                    if (!$isDaemon) {
                        break;
                    }

                    sleep($sleepSeconds);
                    continue;
                }

                $io->text(sprintf(
                    '[Iteration %d] Processing %d tasks (Worker %d/%d)',
                    $iteration,
                    count($tasks),
                    $workerId,
                    $totalWorkers
                ));

                $processed = 0;
                foreach ($tasks as $taskData) {
                    try {
                        $this->taskProcessor->process($taskData);
                        $processed++;
                    } catch (\Exception $e) {
                        $io->error(sprintf(
                            'Task %d failed: %s',
                            $taskData['id'],
                            $e->getMessage()
                        ));
                    }
                }

                $totalProcessed += $processed;

                $io->success(sprintf(
                    '[Iteration %d] Processed %d/%d tasks',
                    $iteration,
                    $processed,
                    count($tasks)
                ));

                if ($isDaemon) {
                    sleep($sleepSeconds);
                }

                // Check if we've exceeded max execution time (only in non-daemon mode)
                if (!$isDaemon && (time() - $startTime) >= $maxExecutionTime) {
                    $io->info('Max execution time reached');
                    break;
                }

            } catch (\Exception $e) {
                $io->error('Error in iteration: ' . $e->getMessage());
                $this->logger->error('Worker error', [
                    'worker_id' => $workerId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                if ($isDaemon) {
                    sleep($sleepSeconds * 2); // Sleep longer on error
                } else {
                    return Command::FAILURE;
                }
            }

        } while ($isDaemon || (time() - $startTime) < $maxExecutionTime);

        $duration = time() - $startTime;

        $io->success(sprintf(
            'Worker %d finished. Total processed: %d tasks in %d seconds (%.2f tasks/sec)',
            $workerId,
            $totalProcessed,
            $duration,
            $duration > 0 ? $totalProcessed / $duration : 0
        ));

        $this->logger->info('Scheduler worker finished', [
            'worker_id' => $workerId,
            'total_processed' => $totalProcessed,
            'duration_seconds' => $duration
        ]);

        return Command::SUCCESS;
    }
}
