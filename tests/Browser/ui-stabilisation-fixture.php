<?php

declare(strict_types=1);

// Development-only browser identity. Never changes the active theme or existing accounts.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$boot = CliBootstrap::boot();
$db = $boot['container']->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-ui-stabilisation-state.json';
if (($argv[1] ?? '') === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous browser fixture first.');
    $fixture = new DevelopmentFixture($db);
    $email = 'ui-stabilisation-' . $fixture->suffix() . '@example.test';
    $user = $fixture->createUser('UI stabilisation browser check', $email);
    $boot['container']->get(RoleRepository::class)->assign($user, 'ADMIN');
    $token = Token::generate();
    $boot['container']->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'UI stabilisation browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created isolated browser identity.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with($state['email'], 'ui-stabilisation-')) throw new RuntimeException('Unexpected fixture identity.');
    $db->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $state['user']]);
    $db->executeStatement('DELETE FROM users WHERE id = :id AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email = :email)', ['id' => $state['user'], 'email' => $state['email']]);
    unlink($statePath);
    echo "Removed browser identity and its activity.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/ui-stabilisation-fixture.php create|cleanup [state-path]');
}
