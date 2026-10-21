<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Puts the catalogue file's categories, tags, icons and covers on the site (owner, 2026-10-20), after a
 * rebuild of the database or on a new installation. Reads the file named, standard input for "-", or with no
 * argument the file published with the code, resources/catalogue/catalogue.json:
 *
 *     php bin/console catalogue:import
 */
#[AsCommand(name: 'catalogue:import', description: 'Put the catalogue file\'s categories, tags, icons and course covers on the site.')]
final class CatalogueImportCommand extends Command
{
    public function __construct(private readonly CatalogueFile $catalogue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'The catalogue file, or - for standard input (default: resources/catalogue/catalogue.json).', CatalogueFile::bundledPath());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = (string) $input->getArgument('file');
        $json = $path === '-' ? (string) stream_get_contents(STDIN) : (is_file($path) ? (string) file_get_contents($path) : '');
        if ($json === '') {
            $output->writeln('<error>No catalogue file was read from ' . ($path === '-' ? 'standard input' : $path) . '.</error>');
            return Command::FAILURE;
        }
        try {
            $report = $this->catalogue->import(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
        } catch (\JsonException|\InvalidArgumentException|\RuntimeException $failed) {
            $output->writeln('<error>' . $failed->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('Categories: %d created, %d already there. Tags: %d created, %d already there. Courses given their tags, cover and position: %d.',
            $report['categories_created'], $report['categories_kept'], $report['tags_created'], $report['tags_kept'], $report['courses_updated']));
        if ($report['courses_missing'] !== []) $output->writeln('Not on the site yet (import them first): ' . implode(', ', $report['courses_missing']) . '.');
        return Command::SUCCESS;
    }
}
