<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use CattoLearning\Maintenance\ScheduledJob;
use CattoLearning\Maintenance\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Recalculates course popularity from cron or by hand; pages only ever read the stored snapshot. */
#[AsCommand(name: 'popularity:recalculate', description: 'Recalculate the course popularity ranking for every eligible course.')]
final class RecalculatePopularityCommand extends Command
{
    public function __construct(private readonly Scheduler $scheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Recorded as the scheduler's popularity job (owner, 2026-10-19), so Settings > Maintenance
        // shows when it last ran however it was started.
        $result = $this->scheduler->run([ScheduledJob::Popularity])[ScheduledJob::Popularity->value];
        if (!$result['ok']) {
            $output->writeln('<error>Popularity recalculation failed: ' . htmlspecialchars($result['summary']) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($result['summary']);
        return Command::SUCCESS;
    }
}
