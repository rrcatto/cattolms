<?php

declare(strict_types=1);

// Development-only fixture for billing-profiles.cjs: a published paid course from a provider
// company; a learner (STUDENT) who buys it; a company administrator (COMPANY_ADMIN) of a company that
// buys credits for it; and an ADMIN for the all-theme pass. `reset` clears their billing profiles so
// each scenario starts from none. `cleanup` removes what the database allows: orders, invoices and
// their purchasers are immutable financial history, so a run that placed orders leaves those orders,
// the two purchasing identities (signed out) and the course (retired) behind. The development
// database is disposable; a reset removes them.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\CheckScrub;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-billing-profiles-state.json';
$action = $argv[1] ?? '';

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous billing-profiles fixture first.');
    $fixture = new DevelopmentFixture($db);
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Billing profiles browser', $email);
        return $token;
    };
    // A course provider and a client company, each made with its founding Company Administrator, a private learner and
    // an administrator: believable names nobody on the site has (owner, 2026-10-27).
    $provider = $fixture->company();
    $course = $fixture->course($provider['founder']['id'], $provider['id'], 'published');
    $variant = $fixture->createPriceVariant($course['id'], $provider['founder']['id'], 2592000, 12345, true);
    $learner = $fixture->person('STUDENT');
    $client = $fixture->company();
    $companyAdmin = $client['founder'];
    $admin = $fixture->person('ADMIN');
    $state = [
        'slug' => $course['slug'], 'course' => $course['id'], 'course_title' => $course['title'], 'variant' => $variant,
        'company' => $client['id'], 'company_name' => $client['name'], 'provider_company' => $provider['id'], 'learner_name' => $learner['name'],
        'users' => ['provider' => $provider['founder']['id'], 'learner' => $learner['id'], 'company_admin' => $companyAdmin['id'], 'admin' => $admin['id']],
        'learner_token' => $session($learner['id'], $learner['email']), 'company_admin_token' => $session($companyAdmin['id'], $companyAdmin['email']),
        'admin_token' => $session($admin['id'], $admin['email']), 'scrub' => $fixture->seeds(),
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created billing-profiles fixture.\n";
} elseif ($action === 'reset' || $action === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $users = array_values(array_map('intval', $state['users']));
    $db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM company_billing_profiles WHERE company_id = :c', ['c' => $state['company']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    // Everything the check made, its orders and their money records included (owner, 2026-10-27).
    (new CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the billing-profiles check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/billing-profiles-fixture.php create|reset|cleanup [state-path]');
}
