<?php

declare(strict_types=1);

// Development-only fixture for promotions.cjs: two published paid courses from a provider company
// (R123.45 and R50.00), an ADMIN, and two promotions: a valid 10% code and an expired one.
// `learner` adds a fresh signed-in learner, with names, ID number and billing details saved, so each
// purchase starts from courses the learner does not own. `cleanup` removes what the database allows:
// orders, their documents, their purchasers, the courses they bought and the promotions they used are
// immutable financial history and stay behind. The development database is disposable; a reset
// removes them.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Infrastructure\BillingProfileRepository;
use CattoLearning\Commerce\Infrastructure\PromotionRepository;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-promotions-state.json';
$action = $argv[1] ?? '';
$fixture = new DevelopmentFixture($db);

$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Promotions browser', $email);
    return $token;
};
$person = static function (string $key, string $first, string $last, array $roleKeys, string $suffix) use ($fixture, $db, $container): array {
    $email = 'promo-browser-' . $key . '-' . $suffix . '@example.test';
    $id = $fixture->createUser($first . ' ' . $last, $email);
    $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
    foreach ($roleKeys as $role) $container->get(RoleRepository::class)->assign($id, $role);
    return ['id' => $id, 'email' => $email];
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous promotions fixture first.');
    $suffix = $fixture->suffix();
    $provider = $person('provider', 'Course', 'Provider', ['STUDENT'], $suffix);
    $company = $fixture->createCompany($provider['id'], 'Promotions browser provider ' . $suffix, 'promo-browser-' . $suffix . '.example.test');
    $courses = [];
    foreach (['a' => 12345, 'b' => 5000] as $key => $price) {
        $slug = 'promo-browser-' . $key . '-' . $suffix;
        $course = $fixture->createCourse($provider['id'], $company, $slug, 'Promotions browser course ' . strtoupper($key) . ' ' . $suffix, 'published');
        $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
        $courses[$key] = ['id' => $course, 'slug' => $slug, 'variant' => $fixture->createPriceVariant($course, $provider['id'], 2592000, $price, true)];
    }
    $admin = $person('admin', 'Promotions', 'Administrator', ['ADMIN'], $suffix);
    $promotions = new PromotionRepository($db);
    $code = 'SAVE' . strtoupper($suffix);
    $expired = 'OLD' . strtoupper($suffix);
    $now = new DateTimeImmutable();
    $base = ['name' => 'Browser ten off', 'description' => null, 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => null, 'starts_at' => null, 'ends_at' => null,
        'active' => true, 'minimum_order_minor' => null, 'maximum_total_uses' => null, 'maximum_uses_per_customer' => null, 'course_scope' => 'all', 'bundle_scope' => 'none'];
    $promotions->create(['code' => $code] + $base, [], $admin['id'], $now->format(DATE_ATOM));
    $promotions->create(['code' => $expired, 'name' => 'Browser expired offer', 'starts_at' => $now->modify('-2 days')->format(DATE_ATOM), 'ends_at' => $now->modify('-1 day')->format(DATE_ATOM)] + $base, [], $admin['id'], $now->format(DATE_ATOM));
    $state = ['suffix' => $suffix, 'company' => $company, 'courses' => $courses, 'code' => $code, 'expired_code' => $expired,
        'users' => ['provider' => $provider['id'], 'admin' => $admin['id']], 'learners' => [], 'admin_token' => $session($admin['id'], $admin['email'])];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created promotions fixture.\n";
} elseif ($action === 'learner') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $key = 'learner' . (count($state['learners']) + 1);
    $learner = $person($key, 'Naledi', 'Mokoena', ['STUDENT'], $state['suffix']);
    $db->executeStatement("UPDATE users SET mobile_number='0821234567', identification_number='9001015009087' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => 'Naledi Mokoena', 'address_line_1' => '5 Promo Lane', 'city' => 'Johannesburg', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    $state['learners'][] = $learner['id'];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $users = [...array_values($state['users']), ...$state['learners']];
    $ids = '{' . implode(',', array_map('intval', $users)) . '}';
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $ids]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[])) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.cart_id = commerce_carts.id)', ['ids' => $ids]);
    // The fixture's promotions and any the ADMIN pass created, unless an order used them.
    $db->executeStatement('DELETE FROM promotions p WHERE (p.code LIKE :suffix OR p.created_by = ANY(CAST(:ids AS BIGINT[]))) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.promotion_id = p.id)',
        ['suffix' => '%' . strtoupper($state['suffix']) . '%', 'ids' => $ids]);
    $ordered = (bool) $db->fetchOne('SELECT 1 FROM commerce_order_items WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => '{' . implode(',', array_column($state['courses'], 'id')) . '}']);
    if (!$ordered) {
        foreach ($state['courses'] as $course) {
            $db->executeStatement('DELETE FROM course_price_variants WHERE course_id = :c', ['c' => $course['id']]);
            $db->executeStatement('DELETE FROM courses WHERE id = :c', ['c' => $course['id']]);
        }
        $db->executeStatement('DELETE FROM company_users WHERE company_id = :c', ['c' => $state['company']]);
        $db->executeStatement('DELETE FROM companies WHERE id = :c', ['c' => $state['company']]);
    } else {
        foreach ($state['courses'] as $course) $db->executeStatement("UPDATE courses SET status='retired' WHERE id = :c", ['c' => $course['id']]);
    }
    foreach ($users as $user) {
        if ($db->fetchOne('SELECT 1 FROM commerce_orders WHERE purchaser_user_id = :u', ['u' => $user]) || $db->fetchOne('SELECT 1 FROM promotions WHERE created_by = :u OR updated_by = :u', ['u' => $user])) continue;
        if ($db->fetchOne('SELECT 1 FROM courses WHERE owner_user_id = :u', ['u' => $user])) continue;
        $db->executeStatement('DELETE FROM analytics_events WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM audit_log WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement("DELETE FROM users WHERE id = :u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'promo-browser-%@example.test')", ['u' => $user]);
    }
    unlink($statePath);
    echo "Removed what the promotions fixture could; orders and what they refer to remain.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/promotions-fixture.php create|learner|cleanup [state-path]');
}
