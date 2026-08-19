<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use DB\SQL;
use PHPUnit\Framework\TestCase;

final class DevelopmentDatabaseSmokeTest extends TestCase
{
    public function testConfiguredDevelopmentDatabaseHasTheExpectedBaseline(): void
    {
        $container = CliBootstrap::boot()['container'];
        /** @var SQL $db */
        $db = $container->get(SQL::class);

        $database = $db->exec('SELECT current_database() AS name');
        self::assertNotSame('', trim((string) ($database[0]['name'] ?? '')));

        $rows = $db->exec("SELECT to_regclass('public.users') AS users, to_regclass('public.courses') AS courses, to_regclass('public.app_options') AS app_options");
        self::assertSame('users', (string) ($rows[0]['users'] ?? ''));
        self::assertSame('courses', (string) ($rows[0]['courses'] ?? ''));
        self::assertSame('app_options', (string) ($rows[0]['app_options'] ?? ''));
    }
}
