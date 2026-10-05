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
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-billing-profiles-state.json';
$action = $argv[1] ?? '';

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous billing-profiles fixture first.');
    $fixture = new DevelopmentFixture($db);
    $suffix = $fixture->suffix();
    $roles = $container->get(RoleRepository::class);
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Billing profiles browser', $email);
        return $token;
    };
    $person = static function (string $key, string $first, string $last, array $roleKeys) use ($fixture, $db, $roles, $suffix): array {
        $email = 'billing-browser-' . $key . '-' . $suffix . '@example.test';
        $id = $fixture->createUser($first . ' ' . $last, $email);
        $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
        foreach ($roleKeys as $role) $roles->assign($id, $role);
        return ['id' => $id, 'email' => $email];
    };
    $provider = $person('provider', 'Course', 'Provider', ['STUDENT']);
    $providerCompany = $fixture->createCompany($provider['id'], 'Billing browser provider ' . $suffix, 'billing-browser-provider-' . $suffix . '.example.test');
    $slug = 'billing-browser-' . $suffix;
    $course = $fixture->createCourse($provider['id'], $providerCompany, $slug, 'Billing browser course ' . $suffix, 'published');
    $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
    $variant = $fixture->createPriceVariant($course, $provider['id'], 2592000, 12345, true);
    $learner = $person('learner', 'Lerato', 'Dlamini', ['STUDENT']);
    $companyAdmin = $person('company-admin', 'Pieter', 'van Wyk', ['STUDENT', 'COMPANY_ADMIN']);
    $company = $fixture->createCompany($companyAdmin['id'], 'Billing Browser Co ' . $suffix, 'billing-browser-co-' . $suffix . '.example.test');
    $db->executeStatement("INSERT INTO company_users (company_id, user_id, company_role, status) VALUES (:c, :u, 'administrator', 'active')", ['c' => $company, 'u' => $companyAdmin['id']]);
    $admin = $person('admin', 'Billing', 'Administrator', ['ADMIN']);
    $state = [
        'suffix' => $suffix, 'slug' => $slug, 'course' => $course, 'course_title' => 'Billing browser course ' . $suffix, 'variant' => $variant,
        'company' => $company, 'company_name' => 'Billing Browser Co ' . $suffix, 'provider_company' => $providerCompany,
        'users' => ['provider' => $provider['id'], 'learner' => $learner['id'], 'company_admin' => $companyAdmin['id'], 'admin' => $admin['id']],
        'learner_token' => $session($learner['id'], $learner['email']), 'company_admin_token' => $session($companyAdmin['id'], $companyAdmin['email']),
        'admin_token' => $session($admin['id'], $admin['email']),
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created billing-profiles fixture.\n";
} elseif ($action === 'reset' || $action === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with((string) $state['slug'], 'billing-browser-')) throw new RuntimeException('Unexpected fixture course.');
    $users = array_values(array_map('intval', $state['users']));
    $db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM company_billing_profiles WHERE company_id = :c', ['c' => $state['company']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id IN (:ids) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.cart_id = commerce_carts.id)', ['ids' => $users]);
    $ordered = (int) $db->fetchOne('SELECT COUNT(*) FROM commerce_order_items WHERE course_id = :c', ['c' => $state['course']]) > 0;
    if ($ordered) {
        // Orders are immutable history: the course stays, retired, and so do its purchasers.
        $db->executeStatement("UPDATE courses SET status = 'retired' WHERE id = :c", ['c' => $state['course']]);
        echo "Removed billing-profiles sessions and profiles; orders, their purchasers and the retired course remain.\n";
    } else {
        $db->executeStatement('DELETE FROM course_price_variants WHERE course_id = :c', ['c' => $state['course']]);
        $db->executeStatement('DELETE FROM courses WHERE id = :c', ['c' => $state['course']]);
        $db->executeStatement('DELETE FROM company_users WHERE company_id IN (:ids)', ['ids' => [$state['company'], $state['provider_company']]]);
        $db->executeStatement('DELETE FROM companies WHERE id IN (:ids)', ['ids' => [$state['company'], $state['provider_company']]]);
        foreach ($users as $user) {
            $db->executeStatement('DELETE FROM analytics_events WHERE user_id = :u', ['u' => $user]);
            $db->executeStatement('DELETE FROM audit_log WHERE user_id = :u', ['u' => $user]);
            $db->executeStatement("DELETE FROM users WHERE id = :u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'billing-browser-%@example.test')", ['u' => $user]);
        }
        echo "Removed billing-profiles fixture.\n";
    }
    unlink($statePath);
} else {
    throw new RuntimeException('Usage: php tests/Browser/billing-profiles-fixture.php create|reset|cleanup [state-path]');
}
