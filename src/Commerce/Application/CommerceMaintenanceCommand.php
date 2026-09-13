<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

/** Runs commerce deadlines and delivery from cron or the development worker container. */
#[AsCommand(name: 'commerce:maintain', description: 'Cancel overdue orders, advance access clocks and deliver invoices.')]
final class CommerceMaintenanceCommand extends Command
{
    public function __construct(private readonly CommerceMaintenance $maintenance) { parent::__construct(); }
    protected function configure(): void { $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run once per minute until stopped.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            try { $this->maintenance->run(); }
            catch (\Throwable $e) {
                $output->writeln('<error>Commerce maintenance failed: '.htmlspecialchars($e->getMessage()).'</error>');
                if (!$input->getOption('watch')) return Command::FAILURE;
            }
            if ($input->getOption('watch')) sleep(60);
        } while ($input->getOption('watch'));
        return Command::SUCCESS;
    }
}
