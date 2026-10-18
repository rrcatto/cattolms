<?php

declare(strict_types=1);

// Development-only fixture for section-pages.cjs: one ADMIN who is also an administrator of the System
// Company, so every Account, Company and Administration menu page opens for the same reader, with a
// session. `cleanup` removes the person (their company membership goes with them) and their activity.
// Never changes the active theme or existing accounts.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$boot = CliBootstrap::boot();
$db = $boot['container']->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-section-pages-state.json';
if (($argv[1] ?? '') === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous section pages fixture first.');
    $fixture = new DevelopmentFixture($db);
    $email = 'section-pages-' . $fixture->suffix() . '@example.test';
    $user = $fixture->createUser('Section pages browser check', $email);
    $boot['container']->get(RoleRepository::class)->assign($user, 'ADMIN');
    // The Company workspace pages need a company to administer; there is no default company (no System
    // Company), so this ADMIN administers a test company of its own.
    $company = $fixture->createCompany($user, 'Section pages browser company', 'section-pages-' . $fixture->suffix() . '.example.test');
    $fixture->addCompanyMember($company, $user, 'administrator');
    $token = Token::generate();
    $boot['container']->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Section pages browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token, 'company' => $company], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created the section pages browser identity.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with($state['email'], 'section-pages-')) throw new RuntimeException('Unexpected fixture identity.');
    $db->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $state['user']]);
    $db->executeStatement('DELETE FROM users WHERE id = :id AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email = :email)', ['id' => $state['user'], 'email' => $state['email']]);
    if ((int) ($state['company'] ?? 0) > 0) $db->executeStatement("DELETE FROM companies WHERE id = :id AND domain LIKE 'section-pages-%'", ['id' => (int) $state['company']]);
    unlink($statePath);
    echo "Removed the section pages browser identity and its activity.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/section-pages-fixture.php create|cleanup [state-path]');
}
