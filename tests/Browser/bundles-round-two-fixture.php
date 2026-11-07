<?php

declare(strict_types=1);

// Development-only fixture for bundles-round-two.cjs (owner, 2026-11-06: bundle codes, the bundle list edited in place,
// status as cards, a bundle on sale later still shown with its date, bundle favourites and Favourites as its own page).
// `create` makes a course provider with its founding Company Administrator and three published priced courses, an
// administrator, and two published bundles of them made by that administrator: one on sale now and one whose sale starts
// in twelve days at 09:30 in the site's time zone, with what the pages should say about it. `learner` adds a private
// learner and `company-learner` a member of the provider's staff, each with a session. `cleanup` removes everything the
// check made: its people, company, courses and their bundles, favourites and requests with them.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\BundleFixture;
use CattoLearning\Tests\Support\CheckNames;
use CattoLearning\Tests\Support\CheckScrub;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-bundles-round-two-state.json';
$action = $argv[1] ?? '';
$fixture = new DevelopmentFixture($db);

$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Bundles round two browser', $email);
    return $token;
};
// A bundle code nobody has, course or bundle: BDL- and six capital letters.
$freeCode = static function () use ($db): string {
    do {
        $code = 'BDL-' . substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ', 3)), 0, 6);
    } while ((int) $db->fetchOne('SELECT (SELECT COUNT(*) FROM courses WHERE code = :c) + (SELECT COUNT(*) FROM bundles WHERE code = :c)', ['c' => $code]) > 0);
    return $code;
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous bundles round two fixture first.');
    $names = new CheckNames($db);
    $company = $fixture->company();
    $courses = [];
    foreach (['a' => 50000, 'b' => 70000, 'c' => 40000] as $key => $price) {
        $course = $fixture->course($company['founder']['id'], $company['id'], 'published');
        $fixture->createPriceVariant($course['id'], $company['founder']['id'], 31536000, $price, true);
        $courses[$key] = ['id' => $course['id'], 'slug' => $course['slug'], 'title' => $course['title'], 'code' => $course['code']];
    }
    $admin = $fixture->person('ADMIN');
    $zone = new DateTimeZone(date_default_timezone_get());
    $start = (new DateTimeImmutable('today', $zone))->modify('+12 days')->setTime(9, 30);
    $now = BundleFixture::create($db, $admin['id'], [$courses['a']['id'], $courses['b']['id']], 100000, ['title' => $names->bundle(), 'was_price' => 150000]);
    $later = BundleFixture::create($db, $admin['id'], [$courses['b']['id'], $courses['c']['id']], 90000, ['title' => $names->bundle(), 'available_from' => $start->format(DATE_ATOM)]);
    $codes = [];
    foreach (['now' => $now, 'later' => $later] as $key => $bundle) {
        $codes[$key] = $freeCode();
        $db->executeStatement('UPDATE bundles SET code = :code WHERE id = :id', ['code' => $codes[$key], 'id' => $bundle['id']]);
    }
    $state = ['company' => $company['id'], 'company_domain' => $company['domain'], 'courses' => $courses, 'admin' => $admin['id'], 'admin_token' => $session($admin['id'], $admin['email']),
        'bundles' => ['now' => ['code' => $codes['now']] + $now, 'later' => ['code' => $codes['later']] + $later],
        // What the pages say about the later one: the long form on its page, the short one elsewhere (brief, 2026-11-06).
        'later_long' => 'On sale from ' . $start->format('l j F Y') . ' at 09:30', 'later_short' => 'On sale from ' . $start->format('j M Y'),
        'later_refusal' => 'This bundle can be bought from ' . $start->format('l j F Y') . ' at 09:30.',
        'new_code' => $freeCode(), 'new_title' => $names->bundle(), 'learners' => [], 'scrub' => $fixture->seeds()];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created bundles round two fixture.\n";
} elseif ($action === 'learner' || $action === 'company-learner') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $learner = $action === 'company-learner' ? $fixture->staff(['id' => (int) $state['company'], 'domain' => (string) $state['company_domain']]) : $fixture->person('STUDENT');
    $state['learners'][] = $learner['id'];
    $state['scrub']['users'][] = $learner['id'];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    (new CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the bundles round two check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/bundles-round-two-fixture.php create|learner|company-learner|cleanup [state-path]');
}
