<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Recalculates course popularity from cron or by hand; pages only ever read the stored snapshot. */
#[AsCommand(name: 'popularity:recalculate', description: 'Recalculate the course popularity ranking for every eligible course.')]
final class RecalculatePopularityCommand extends Command
{
    public function __construct(private readonly CoursePopularityCalculator $calculator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $run = $this->calculator->recalculate();
        } catch (\Throwable $e) {
            $output->writeln('<error>Popularity recalculation failed: ' . htmlspecialchars($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            'Popularity recalculated for %d course%s (window %s to %s, run %d).',
            $run['courses'], $run['courses'] === 1 ? '' : 's',
            $run['window_start']->format('Y-m-d H:i'), $run['window_end']->format('Y-m-d H:i'), $run['run_id']
        ));
        return Command::SUCCESS;
    }
}
