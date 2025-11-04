<?php

namespace App\Command;

use App\Repository\ScheduledTaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Display task statistics (pending, processing, completed, failed, overdue).
 *
 * Example: php bin/console app:scheduler:stats
 */
#[AsCommand(
    name: 'app:scheduler:stats',
    description: 'Display statistics about scheduled tasks',
)]
class SchedulerStatsCommand extends Command
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Scheduler Statistics');

        $stats = $this->taskRepository->getStatistics();
        $overdueCount = $this->taskRepository->getOverdueCount();

        if (empty($stats)) {
            $io->warning('No tasks found in the system');
            return Command::SUCCESS;
        }

        // Prepare table data
        $tableData = [];
        $total = 0;

        foreach ($stats as $status => $data) {
            $count = $data['count'];
            $total += $count;

            $tableData[] = [
                $status,
                number_format($count),
                $data['oldest_scheduled'] ?? 'N/A',
                $data['newest_scheduled'] ?? 'N/A',
            ];
        }

        $io->table(
            ['Status', 'Count', 'Oldest Scheduled', 'Newest Scheduled'],
            $tableData
        );

        $io->section('Summary');
        $io->text([
            sprintf('Total tasks: <info>%s</info>', number_format($total)),
            sprintf('Overdue tasks: <comment>%s</comment>', number_format($overdueCount)),
        ]);

        if ($overdueCount > 0) {
            $io->warning(sprintf(
                '%d tasks are overdue and should be processed immediately!',
                $overdueCount
            ));
        }

        return Command::SUCCESS;
    }
}
