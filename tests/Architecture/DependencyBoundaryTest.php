<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class DependencyBoundaryTest extends TestCase
{
    public function testApplicationClassesDoNotUseServiceLocatorContainer(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $allowed = [
            'Application/ContainerFactory.php',
            'Application/CliBootstrap.php',
            'Plugin/PluginManager.php',
        ];
        $violations = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, $allowed, true)) continue;
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'Psr\\Container\\ContainerInterface') || str_contains($source, 'DI\\Container')) {
                $violations[] = $relative;
            }
        }
        self::assertSame([], $violations, 'Container/service-locator dependency leaked into: ' . implode(', ', $violations));
    }

    public function testLegacyAppContextAndServiceFactoryAreGone(): void
    {
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/Application/AppContext.php');
        self::assertFileDoesNotExist(dirname(__DIR__, 2) . '/src/Application/ServiceFactory.php');
    }
}