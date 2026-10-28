<?php

declare(strict_types=1);

// Development-only fixture for section-pages.cjs (and the checks that borrow its reader): the founding Company
// Administrator of a believable company who is also ADMIN, so every Account, Company and Administration page opens for
// the same reader, with a session. The company is made with its administrator, as every company is, and every name is
// one nobody on the site has (owner, 2026-10-27). `cleanup` removes everything the checks made through this reader
// (CheckScrub). Never changes the active theme or anyone else's account.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\CheckScrub;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$boot = CliBootstrap::boot();
$db = $boot['container']->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-section-pages-state.json';
if (($argv[1] ?? '') === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous section pages fixture first.');
    $fixture = new DevelopmentFixture($db);
    $company = $fixture->company();
    $user = $company['founder'];
    $boot['container']->get(RoleRepository::class)->assign($user['id'], 'ADMIN');
    // A draft course of the company's own, so Course Creators and the company page's courses always have one to show
    // whatever the site holds (a draft is never in the catalogue).
    $fixture->course($user['id'], $company['id'], 'draft');
    $token = Token::generate();
    $boot['container']->get(AuthSessionRepository::class)->create($user['id'], Token::hash($token), 7200, '', 'Section pages browser', $user['email']);
    file_put_contents($statePath, json_encode(['user' => $user['id'], 'email' => $user['email'], 'name' => $user['name'], 'token' => $token, 'company' => $company['id'], 'company_name' => $company['name'], 'scrub' => $fixture->seeds()], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created the section pages browser identity: {$user['name']} of {$company['name']}.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $removed = (new CheckScrub($db))->remove($state['scrub'] ?? ['users' => [(int) $state['user']], 'companies' => [(int) ($state['company'] ?? 0)]]);
    unlink($statePath);
    echo 'Removed everything the checks made through this reader: ' . array_sum($removed) . " rows.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/section-pages-fixture.php create|cleanup [state-path]');
}
