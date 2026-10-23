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
 * Writes the site's catalogue package (CataloguePackage), as Administration -> Courses -> Import & Export Catalogue
 * does (owner, 2026-10-23: the page is the way; this is for scripts): the categories and tags, every course with its
 * content, code and place in the catalogue, and the files they use, to the zip named. With --categories-and-tags, the
 * categories and tags alone as a catalogue file, to the file named or standard output for "-", as the file published
 * with the code:
 *
 *     php bin/console catalogue:export --categories-and-tags - > resources/catalogue/catalogue.json
 */
#[AsCommand(name: 'catalogue:export', description: 'Write the site\'s catalogue package (or, with --categories-and-tags, its categories and tags).')]
final class CatalogueExportCommand extends Command
{
    public function __construct(private readonly CataloguePackage $package, private readonly RoleRepository $roles)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Where to write the package (.zip), or with --categories-and-tags the catalogue file, - for standard output.');
        $this->addOption('categories-and-tags', null, InputOption::VALUE_NONE, 'The categories and tags only, as the catalogue file published with the code.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->roles->firstUserWithRole('ADMIN');
        if ($actor === null) {
            $output->writeln('<error>Sign ADMIN in once first: the export is made as ADMIN.</error>');
            return Command::FAILURE;
        }
        $path = (string) $input->getArgument('file');
        if ($input->getOption('categories-and-tags')) {
            $json = $this->package->exportCategoriesAndTags($actor);
            if ($path === '-') {
                // Straight to standard output, whatever the console's verbosity, so it can be redirected to the file.
                fwrite(STDOUT, $json);
                return Command::SUCCESS;
            }
            if (file_put_contents($path, $json) === false) {
                $output->writeln('<error>The catalogue file could not be written to ' . $path . '.</error>');
                return Command::FAILURE;
            }
            $output->writeln('Wrote the categories and tags to ' . $path . '.');
            return Command::SUCCESS;
        }
        $zip = $this->package->export($actor);
        $written = $path === '-' ? (bool) fwrite(STDOUT, (string) file_get_contents($zip)) : copy($zip, $path);
        @unlink($zip);
        if (!$written) {
            $output->writeln('<error>The catalogue package could not be written to ' . $path . '.</error>');
            return Command::FAILURE;
        }
        if ($path !== '-') $output->writeln('Wrote the catalogue package to ' . $path . '.');
        return Command::SUCCESS;
    }
}
