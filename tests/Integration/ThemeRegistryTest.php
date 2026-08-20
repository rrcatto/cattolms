<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\ThemeRegistryRepository;
use CattoLearning\View\ThemeManager;
use PHPUnit\Framework\TestCase;

final class ThemeRegistryTest extends TestCase
{
    public function testRegistryCanBeRebuiltFromAuthoritativeFilesystem(): void
    {
        $container = CliBootstrap::boot()['container'];
        /** @var ThemeManager $themes */
        $themes = $container->get(ThemeManager::class);
        /** @var ThemeRegistryRepository $registry */
        $registry = $container->get(ThemeRegistryRepository::class);

        $themes->resyncRegistry();
        $listed = $themes->themes();
        $rows = $registry->byInstallKey();

        self::assertNotSame([], $listed);
        self::assertCount(count($listed), $rows);
        foreach ($listed as $theme) {
            self::assertArrayHasKey((string) $theme['key'], $rows);
            self::assertTrue((bool) $theme['registry_synced']);
            if ((bool) $theme['is_child'] && (string) $theme['parent_key'] !== '') {
                self::assertGreaterThan(0, (int) $rows[(string) $theme['key']]['parent_theme_id']);
            }
        }
    }
}