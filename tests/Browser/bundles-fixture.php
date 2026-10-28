<?php

declare(strict_types=1);

// Development-only fixture for bundles.cjs: four published paid courses from a provider company
// (R500, R700, R400 and R300), an ADMIN, and a promotion for all bundles (10% off). `learner` adds a
// fresh signed-in learner with names, ID number and billing details saved; `learner-owning-a` adds
// one who already has course A; `company-learner` one who is a member of the provider company, a
// company account, which never buys. `cleanup` removes what the database allows: orders, their documents,
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
use CattoLearning\Tests\Support\CheckScrub;
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

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous bundles fixture first.');
    // A course provider made with its founding administrator, four of its courses on sale, an administrator, a promo
    // code for bundles and the title the bundle will be given: believable and unused (owner, 2026-10-27).
    $names = new \CattoLearning\Tests\Support\CheckNames($db);
    $company = $fixture->company();
    $courses = [];
    foreach (['a' => 50000, 'b' => 70000, 'c' => 40000, 'd' => 30000] as $key => $price) {
        $course = $fixture->course($company['founder']['id'], $company['id'], 'published');
        $fixture->createPriceVariant($course['id'], $company['founder']['id'], 2592000, $price, true);
        $courses[$key] = ['id' => $course['id'], 'slug' => $course['slug'], 'title' => $course['title']];
    }
    $admin = $fixture->person('ADMIN');
    $code = $names->promotion();
    $promotion = (new PromotionRepository($db))->create(['code' => $code, 'name' => 'Ten percent off bundles', 'description' => null, 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => null,
        'starts_at' => null, 'ends_at' => null, 'active' => true, 'minimum_order_minor' => null, 'maximum_total_uses' => null, 'maximum_uses_per_customer' => null,
        'course_scope' => 'none', 'bundle_scope' => 'all'], [], $admin['id'], (new DateTimeImmutable())->format(DATE_ATOM));
    $scrub = $fixture->seeds() + ['promotions' => [$promotion]];
    $state = ['company' => $company['id'], 'company_domain' => $company['domain'], 'courses' => $courses, 'code' => $code, 'bundle_title' => $names->bundle(),
        'users' => ['provider' => $company['founder']['id'], 'admin' => $admin['id']], 'learners' => [], 'admin_token' => $session($admin['id'], $admin['email']), 'scrub' => $scrub];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created bundles fixture.\n";
} elseif ($action === 'learner' || $action === 'learner-owning-a' || $action === 'company-learner') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    // A private learner, or a member of the provider's staff at its domain: a believable name nobody has.
    $learner = $action === 'company-learner' ? $fixture->staff(['id' => (int) $state['company'], 'domain' => (string) $state['company_domain']]) : $fixture->person('STUDENT');
    $db->executeStatement("UPDATE users SET mobile_number='0821234567' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => $learner['name'], 'address_line_1' => '7 Florida Road', 'city' => 'Durban', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    // ADMIN assigns course A from the person's page, as in the app (owner, 2026-10-19).
    if ($action === 'learner-owning-a') $container->get(\CattoLearning\Course\CourseService::class)->grantToPerson((int) $state['courses']['a']['id'], $learner['id'], 30, (int) $state['users']['admin']);
    $state['learners'][] = $learner['id'];
    $state['scrub']['users'][] = $learner['id'];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    // Everything the check made: its bundles (made by its administrator), its orders and their money records included
    // (owner, 2026-10-27: "you can remove the data without worrying about the rules").
    (new CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the bundles check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/bundles-fixture.php create|learner|learner-owning-a|company-learner|cleanup [state-path]');
}
