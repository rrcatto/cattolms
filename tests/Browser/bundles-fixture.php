<?php

declare(strict_types=1);

// Development-only fixture for bundles.cjs: four published paid courses from a provider company
// (R500, R700, R400 and R300), an ADMIN, and a promotion for all bundles (10% off). `learner` adds a
// fresh signed-in learner with names, ID number and billing details saved; `learner-owning-a` adds
// one who already has course A. `cleanup` removes what the database allows: orders, their documents,
// purchasers, the courses and bundles they bought and the promotion they used are immutable history
// and stay behind. The development database is disposable; a reset removes them.

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
$statePath = $argv[2] ?? '/tmp/catto-bundles-state.json';
$action = $argv[1] ?? '';
$fixture = new DevelopmentFixture($db);

$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Bundles browser', $email);
    return $token;
};
$person = static function (string $key, string $first, string $last, array $roleKeys, string $suffix) use ($fixture, $db, $container): array {
    $email = 'bundle-browser-' . $key . '-' . $suffix . '@example.test';
    $id = $fixture->createUser($first . ' ' . $last, $email);
    $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
    foreach ($roleKeys as $role) $container->get(RoleRepository::class)->assign($id, $role);
    return ['id' => $id, 'email' => $email];
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous bundles fixture first.');
    $suffix = $fixture->suffix();
    $provider = $person('provider', 'Course', 'Provider', ['STUDENT'], $suffix);
    $company = $fixture->createCompany($provider['id'], 'Bundles browser provider ' . $suffix, 'bundle-browser-' . $suffix . '.example.test');
    $courses = [];
    foreach (['a' => 50000, 'b' => 70000, 'c' => 40000, 'd' => 30000] as $key => $price) {
        $slug = 'bundle-browser-' . $key . '-' . $suffix;
        $title = 'Bundle browser course ' . strtoupper($key) . ' ' . $suffix;
        $course = $fixture->createCourse($provider['id'], $company, $slug, $title, 'published');
        $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
        $fixture->createPriceVariant($course, $provider['id'], 2592000, $price, true);
        $courses[$key] = ['id' => $course, 'slug' => $slug, 'title' => $title];
    }
    $admin = $person('admin', 'Bundles', 'Administrator', ['ADMIN'], $suffix);
    $code = 'BUNDLE' . strtoupper($suffix);
    (new PromotionRepository($db))->create(['code' => $code, 'name' => 'Browser bundle offer', 'description' => null, 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => null,
        'starts_at' => null, 'ends_at' => null, 'active' => true, 'minimum_order_minor' => null, 'maximum_total_uses' => null, 'maximum_uses_per_customer' => null,
        'course_scope' => 'none', 'bundle_scope' => 'all'], [], $admin['id'], (new DateTimeImmutable())->format(DATE_ATOM));
    $state = ['suffix' => $suffix, 'company' => $company, 'courses' => $courses, 'code' => $code, 'bundle_title' => 'Office Skills Bundle ' . $suffix,
        'users' => ['provider' => $provider['id'], 'admin' => $admin['id']], 'learners' => [], 'admin_token' => $session($admin['id'], $admin['email'])];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created bundles fixture.\n";
} elseif ($action === 'learner' || $action === 'learner-owning-a') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $learner = $person('learner' . (count($state['learners']) + 1), 'Sipho', 'Ndlovu', ['STUDENT'], $state['suffix']);
    $db->executeStatement("UPDATE users SET mobile_number='0821234567', identification_number='9001015009087' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => 'Sipho Ndlovu', 'address_line_1' => '7 Bundle Road', 'city' => 'Durban', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    if ($action === 'learner-owning-a') $fixture->createEnrolment($learner['id'], (int) $state['courses']['a']['id'], (int) $state['users']['admin']);
    $state['learners'][] = $learner['id'];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $users = [...array_values($state['users']), ...$state['learners']];
    $ids = '{' . implode(',', array_map('intval', $users)) . '}';
    $courseIds = '{' . implode(',', array_column($state['courses'], 'id')) . '}';
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $ids]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[])) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.cart_id = commerce_carts.id)', ['ids' => $ids]);
    $db->executeStatement('DELETE FROM promotions p WHERE p.code = :code AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.promotion_id = p.id)', ['code' => $state['code']]);
    // Bundles the ADMIN pass made from these courses, unless one was sold.
    $db->executeStatement('DELETE FROM bundles b WHERE b.created_by_user_id = ANY(CAST(:ids AS BIGINT[])) AND NOT EXISTS (SELECT 1 FROM commerce_order_items i WHERE i.bundle_id = b.id)', ['ids' => $ids]);
    $db->executeStatement("UPDATE bundles SET status='retired' WHERE created_by_user_id = ANY(CAST(:ids AS BIGINT[]))", ['ids' => $ids]);
    $held = (bool) $db->fetchOne('SELECT 1 FROM commerce_bundle_grants WHERE course_id = ANY(CAST(:ids AS BIGINT[])) UNION SELECT 1 FROM bundle_courses WHERE course_id = ANY(CAST(:ids AS BIGINT[])) UNION SELECT 1 FROM commerce_order_items WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courseIds]);
    if (!$held) {
        $db->executeStatement('DELETE FROM course_enrolments WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courseIds]);
        $db->executeStatement('DELETE FROM course_price_variants WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courseIds]);
        $db->executeStatement('DELETE FROM courses WHERE id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courseIds]);
        $db->executeStatement('DELETE FROM company_users WHERE company_id = :c', ['c' => $state['company']]);
        $db->executeStatement('DELETE FROM companies WHERE id = :c', ['c' => $state['company']]);
    } else {
        $db->executeStatement("UPDATE courses SET status='retired' WHERE id = ANY(CAST(:ids AS BIGINT[]))", ['ids' => $courseIds]);
    }
    foreach ($users as $user) {
        if ($db->fetchOne('SELECT 1 FROM commerce_orders WHERE purchaser_user_id = :u', ['u' => $user]) || $db->fetchOne('SELECT 1 FROM bundles WHERE created_by_user_id = :u OR updated_by_user_id = :u UNION SELECT 1 FROM promotions WHERE created_by = :u OR updated_by = :u', ['u' => $user])) continue;
        if ($db->fetchOne('SELECT 1 FROM courses WHERE owner_user_id = :u', ['u' => $user])) continue;
        $db->executeStatement('DELETE FROM course_enrolments WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM analytics_events WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM audit_log WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id = :u', ['u' => $user]);
        $db->executeStatement("DELETE FROM users WHERE id = :u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'bundle-browser-%@example.test')", ['u' => $user]);
    }
    unlink($statePath);
    echo "Removed what the bundles fixture could; orders and what they refer to remain.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/bundles-fixture.php create|learner|learner-owning-a|cleanup [state-path]');
}
