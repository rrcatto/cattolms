<?php

declare(strict_types=1);

// Development-only fixture for legal-pages.cjs: a published paid course from a provider company; a
// learner (STUDENT) who takes it to the checkout review; a company administrator (COMPANY_ADMIN) of
// a company that takes credits for it to the company checkout; and an ADMIN in whose name each
// bundled theme is activated, so signed-out requests are drawn in it too. `theme <key>` activates
// a theme and `restore` puts back the one that was active at `create`. `orders` counts the orders
// the purchasers placed (the check places none), and `cleanup` restores the theme and removes
// everything, which it can because nothing here is immutable financial history.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;
use CattoLearning\View\ThemeManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$themes = $container->get(ThemeManager::class);
$action = $argv[1] ?? '';
$statePath = '/tmp/catto-legal-pages-state.json';
$read = static function () use ($statePath): array {
    if (!is_file($statePath)) throw new RuntimeException('Create the legal-pages fixture first.');
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with((string) $state['slug'], 'legal-browser-')) throw new RuntimeException('Unexpected fixture course.');
    return $state;
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous legal-pages fixture first.');
    $fixture = new DevelopmentFixture($db);
    $suffix = $fixture->suffix();
    $roles = $container->get(RoleRepository::class);
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Legal pages browser', $email);
        return $token;
    };
    $person = static function (string $key, string $first, string $last, array $roleKeys) use ($fixture, $db, $roles, $suffix): array {
        $email = 'legal-browser-' . $key . '-' . $suffix . '@example.test';
        $id = $fixture->createUser($first . ' ' . $last, $email);
        $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
        foreach ($roleKeys as $role) $roles->assign($id, $role);
        return ['id' => $id, 'email' => $email];
    };
    $provider = $person('provider', 'Course', 'Provider', ['STUDENT']);
    $providerCompany = $fixture->createCompany($provider['id'], 'Legal browser provider ' . $suffix, 'legal-browser-provider-' . $suffix . '.example.test');
    $slug = 'legal-browser-' . $suffix;
    $course = $fixture->createCourse($provider['id'], $providerCompany, $slug, 'Legal browser course ' . $suffix, 'published');
    $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
    $variant = $fixture->createPriceVariant($course, $provider['id'], 2592000, 12345, true);
    $learner = $person('learner', 'Lerato', 'Dlamini', ['STUDENT']);
    $companyAdmin = $person('company-admin', 'Pieter', 'van Wyk', ['STUDENT', 'COMPANY_ADMIN']);
    $company = $fixture->createCompany($companyAdmin['id'], 'Legal Browser Co ' . $suffix, 'legal-browser-co-' . $suffix . '.example.test');
    $db->executeStatement("INSERT INTO company_users (company_id, user_id, company_role, status) VALUES (:c, :u, 'administrator', 'active')", ['c' => $company, 'u' => $companyAdmin['id']]);
    $admin = $person('admin', 'Legal', 'Administrator', ['ADMIN']);
    $state = [
        'suffix' => $suffix, 'slug' => $slug, 'course' => $course, 'course_title' => 'Legal browser course ' . $suffix, 'variant' => $variant,
        'company' => $company, 'provider_company' => $providerCompany, 'theme' => $themes->activeTheme(),
        'users' => ['provider' => $provider['id'], 'learner' => $learner['id'], 'company_admin' => $companyAdmin['id'], 'admin' => $admin['id']],
        'learner_name' => 'Lerato Dlamini', 'learner_token' => $session($learner['id'], $learner['email']),
        'company_admin_token' => $session($companyAdmin['id'], $companyAdmin['email']),
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
} elseif ($action === 'theme') {
    $themes->activate((string) ($argv[2] ?? ''), (int) $read()['users']['admin']);
    echo "Theme selected.\n";
} elseif ($action === 'restore') {
    $state = $read();
    $themes->activate((string) $state['theme'], (int) $state['users']['admin']);
    echo "Theme restored.\n";
} elseif ($action === 'orders') {
    $state = $read();
    echo (int) $db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id IN (:ids)', ['ids' => [(int) $state['users']['learner'], (int) $state['users']['company_admin']]]), "\n";
} elseif ($action === 'cleanup') {
    $state = $read();
    if ($themes->activeTheme() !== (string) $state['theme']) $themes->activate((string) $state['theme'], (int) $state['users']['admin']);
    $users = array_values(array_map('intval', $state['users']));
    if ((int) $db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id IN (:ids)', ['ids' => $users]) > 0) {
        throw new RuntimeException('The legal-pages check placed an order, which it never should; remove the fixture by resetting the development database.');
    }
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id IN (:ids)', ['ids' => $users]);
    $db->executeStatement('DELETE FROM company_billing_profiles WHERE company_id = :c', ['c' => $state['company']]);
    $db->executeStatement('DELETE FROM analytics_events WHERE course_id = :c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM course_price_variants WHERE course_id = :c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM courses WHERE id = :c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM company_users WHERE company_id IN (:ids)', ['ids' => [$state['company'], $state['provider_company']]]);
    $db->executeStatement('DELETE FROM companies WHERE id IN (:ids)', ['ids' => [$state['company'], $state['provider_company']]]);
    foreach ($users as $user) {
        $db->executeStatement('DELETE FROM analytics_events WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM audit_log WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement("DELETE FROM users WHERE id = :u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'legal-browser-%@example.test')", ['u' => $user]);
    }
    unlink($statePath);
    echo "Removed legal-pages fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/legal-pages-fixture.php create|theme <key>|restore|orders|cleanup');
}
