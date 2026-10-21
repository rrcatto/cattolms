<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the site's catalogue to a catalogue file (owner, 2026-10-20): the categories as a tree, the
 * tags, every icon and cover, and each course's category, tags and price. To the file named, or standard
 * output for "-". The file published with the code holds only the categories and tags:
 *
 *     php bin/console catalogue:export --without-courses - > resources/catalogue/catalogue.json
 *     php bin/console catalogue:export - > <a private file, with the site's own courses and prices>
 */
#[AsCommand(name: 'catalogue:export', description: 'Write the site\'s categories, tags, icons and course settings to the catalogue file.')]
final class CatalogueExportCommand extends Command
{
    public function __construct(private readonly CatalogueFile $catalogue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Where to write the catalogue file, or - for standard output.');
        // The public file holds only the categories and tags (owner, 2026-10-20): a site's own courses, their
        // pictures and prices stay in a private copy.
        $this->addOption('without-courses', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Leave the courses out: the categories and tags only, as the file published with the code.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $catalogue = $this->catalogue->export();
        if ($input->getOption('without-courses')) $catalogue['courses'] = [];
        $json = json_encode($catalogue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $path = (string) $input->getArgument('file');
        if ($path === '-') {
            // Straight to standard output, whatever the console's verbosity, so it can be redirected to the file.
            fwrite(STDOUT, $json);
            return Command::SUCCESS;
        }
        if (file_put_contents($path, $json) === false) {
            $output->writeln('<error>The catalogue file could not be written to ' . $path . '.</error>');
            return Command::FAILURE;
        }
        $output->writeln('Wrote the catalogue to ' . $path . '.');
        return Command::SUCCESS;
    }
}
