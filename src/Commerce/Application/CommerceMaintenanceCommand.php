<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Maintenance\ScheduledJob;
use CattoLearning\Maintenance\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the commerce job alone: deadlines, access clocks and the emails. Each run is recorded as the
 * scheduler's commerce job (owner, 2026-10-19), so the dashboard knows it ran; schedule:run is the
 * one scheduler an installation runs (docs/OPERATIONS.md, "Scheduled work").
 */
#[AsCommand(name: 'commerce:maintain', description: 'Cancel overdue orders, advance access clocks and deliver invoices.')]
final class CommerceMaintenanceCommand extends Command
{
    public function __construct(private readonly Scheduler $scheduler) { parent::__construct(); }
    protected function configure(): void { $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run once per minute until stopped.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $result = $this->scheduler->run([ScheduledJob::Commerce])[ScheduledJob::Commerce->value];
            if (!$result['ok']) {
                $output->writeln('<error>Commerce maintenance failed: '.htmlspecialchars($result['summary']).'</error>');
                if (!$input->getOption('watch')) return Command::FAILURE;
            }
            if ($input->getOption('watch')) sleep(60);
        } while ($input->getOption('watch'));
        return Command::SUCCESS;
    }
}
