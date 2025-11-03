<?php

namespace App\Command;

use App\Repository\ScheduledTaskRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:scheduler:cleanup',
    description: 'Clean up old completed and failed tasks',
)]
class SchedulerCleanupCommand extends Command
{
    public function __construct(
        private ScheduledTaskRepository $taskRepository,
        private ParameterBagInterface $params
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete tasks older than this many days',
            $this->params->get('scheduler.cleanup_days')
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        $io->title('Scheduler Cleanup');
        $io->text(sprintf('Deleting completed and failed tasks older than %d days...', $days));

        $deleted = $this->taskRepository->cleanupOldTasks($days);

        if ($deleted > 0) {
            $io->success(sprintf('Deleted %d old tasks', $deleted));
        } else {
            $io->info('No old tasks to delete');
        }

        return Command::SUCCESS;
    }
}
