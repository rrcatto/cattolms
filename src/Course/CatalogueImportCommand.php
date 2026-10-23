<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Infrastructure\Persistence\RoleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Loads a catalogue package or catalogue file (CataloguePackage), as Administration -> Courses -> Import & Export
 * Catalogue does without the review (owner, 2026-10-23: the page is the way; this is for scripts): every course, the
 * package's prices for new courses and new courses published as they were, unless told otherwise. With no argument, the
 * starter categories and tags published with the code, resources/catalogue/catalogue.json:
 *
 *     php bin/console catalogue:import
 *     php bin/console catalogue:import site-catalogue.zip --no-prices --no-publish
 */
#[AsCommand(name: 'catalogue:import', description: 'Load a catalogue package (.zip) or catalogue file (.json) onto the site.')]
final class CatalogueImportCommand extends Command
{
    public function __construct(private readonly CataloguePackage $package, private readonly RoleRepository $roles)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'The catalogue package (.zip) or catalogue file (.json); - reads a catalogue file from standard input.', CatalogueFile::bundledPath());
        $this->addOption('no-prices', null, InputOption::VALUE_NONE, 'Leave new courses without the package\'s prices.');
        $this->addOption('no-publish', null, InputOption::VALUE_NONE, 'Leave new courses as drafts.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->roles->firstUserWithRole('ADMIN');
        if ($actor === null) {
            $output->writeln('<error>Sign ADMIN in once before importing the catalogue: the import is made as ADMIN.</error>');
            return Command::FAILURE;
        }
        $path = (string) $input->getArgument('file');
        $temporary = null;
        try {
            if ($path === '-') {
                $temporary = (string) tempnam(sys_get_temp_dir(), 'catalogue');
                file_put_contents($temporary, (string) stream_get_contents(STDIN));
                $key = $this->package->stageFile($temporary, 'catalogue.json', $actor);
            } else {
                $key = $this->package->stageFile($path, basename($path), $actor);
            }
            $report = $this->package->apply($key, ['prices' => !$input->getOption('no-prices'), 'publish' => !$input->getOption('no-publish')], $actor);
        } catch (\InvalidArgumentException|\RuntimeException $failed) {
            $output->writeln('<error>' . $failed->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            if ($temporary !== null) @unlink($temporary);
        }
        $output->writeln(sprintf('Categories: %d created, %d already there. Tags: %d created, %d already there. Files installed: %d.',
            $report['catalogue']['categories_created'], $report['catalogue']['categories_kept'], $report['catalogue']['tags_created'], $report['catalogue']['tags_kept'], $report['files_installed']));
        foreach ($report['courses'] as $course) {
            $output->writeln(sprintf('%s %s: %s%s', $course['code'], $course['title'], $course['outcome'], $course['details'] === [] ? '' : ' (' . implode('; ', $course['details']) . ')'));
        }
        $counts = $report['counts'];
        $output->writeln(sprintf('Courses: %d imported, %d updated, %d content replaced, %d unchanged, %d skipped, %d failed.',
            $counts['imported'], $counts['updated'], $counts['replaced'], $counts['unchanged'], $counts['skipped'], $counts['failed']));
        return $counts['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
