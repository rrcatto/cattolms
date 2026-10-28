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

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous promotions fixture first.');
    // A course provider made with its founding administrator, two of its courses on sale, an administrator and four
    // promo codes as a shop writes them, every name one nobody on the site has (owner, 2026-10-27).
    $provider = $fixture->company();
    $company = $provider['id'];
    $courses = [];
    foreach (['a' => 12345, 'b' => 5000] as $key => $price) {
        $made = $fixture->course($provider['founder']['id'], $company, 'published');
        $courses[$key] = ['id' => $made['id'], 'slug' => $made['slug'], 'title' => $made['title'], 'variant' => $fixture->createPriceVariant($made['id'], $provider['founder']['id'], 2592000, $price, true)];
    }
    $admin = $fixture->person('ADMIN');
    $promotions = new PromotionRepository($db);
    [$code, $expired, $adminCode, $auto] = (new \CattoLearning\Tests\Support\CheckNames($db))->promotions(4);
    $now = new DateTimeImmutable();
    $base = ['name' => 'Ten percent off', 'description' => null, 'discount_type' => 'percentage', 'discount_value' => 1000, 'currency' => null, 'starts_at' => null, 'ends_at' => null,
        'active' => true, 'minimum_order_minor' => null, 'maximum_total_uses' => null, 'maximum_uses_per_customer' => null, 'course_scope' => 'all', 'bundle_scope' => 'none'];
    $promotions->create(['code' => $code] + $base, [], $admin['id'], $now->format(DATE_ATOM));
    $promotions->create(['code' => $expired, 'name' => 'Last week\'s offer', 'starts_at' => $now->modify('-2 days')->format(DATE_ATOM), 'ends_at' => $now->modify('-1 day')->format(DATE_ATOM)] + $base, [], $admin['id'], $now->format(DATE_ATOM));
    $state = ['company' => $company, 'courses' => $courses, 'code' => $code, 'expired_code' => $expired, 'admin_code' => strtolower($adminCode), 'auto_code' => $auto, 'scrub' => $fixture->seeds(),
        'users' => ['provider' => $provider['founder']['id'], 'admin' => $admin['id']], 'learners' => [], 'admin_token' => $session($admin['id'], $admin['email'])];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created promotions fixture.\n";
} elseif ($action === 'learner') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $learner = $fixture->person('STUDENT');
    $db->executeStatement("UPDATE users SET mobile_number='0821234567' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => $learner['name'], 'address_line_1' => '5 Jan Smuts Avenue', 'city' => 'Johannesburg', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    $state['scrub']['users'][] = $learner['id'];
    $state['learners'][] = $learner['id'];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    // Everything the check made, the promotions its administrator created through the pages and the orders that used them
    // included (owner, 2026-10-27).
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the promotions check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/promotions-fixture.php create|learner|cleanup [state-path]');
}
