<?php

declare(strict_types=1);

// Development-only fixture for demo-site.cjs: two published paid courses from a provider company
// (A R300.00, B R200.00), an ADMIN with a session, and a signed-in learner with names, a cell number
// and billing details; it removes any saved site mode, so APP_SITE_MODE decides (demo on catto.test).
// `order <learner>` prints the learner's latest order: whether its snapshot is a simulation and what its
// invoice says. `theme <key>` activates a theme and `restore` puts back the one that was active.
// `cleanup` removes the sessions, carts and any saved site mode and retires the courses: orders and
// their documents are immutable history and stay behind. The development database is disposable.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Infrastructure\BillingProfileRepository;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Configuration\SiteMode;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;
use CattoLearning\View\ThemeManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$action = $argv[1] ?? '';
$statePath = $argv[2] ?? '/tmp/catto-demo-site-state.json';
$fixture = new DevelopmentFixture($db);
$readState = static fn(): array => json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
$writeState = static function (array $state) use ($statePath): void {
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
};
$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Demo site browser', $email);
    return $token;
};
$person = static function (string $key, string $first, string $last, string $role, string $suffix) use ($fixture, $db, $container): array {
    $email = 'demo-browser-' . $key . '-' . $suffix . '@example.test';
    $id = $fixture->createUser($first . ' ' . $last, $email);
    $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
    $container->get(RoleRepository::class)->assign($id, $role);
    return ['id' => $id, 'email' => $email];
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous demo site fixture first.');
    $db->executeStatement('DELETE FROM app_options WHERE option_key = :k', ['k' => SiteMode::OPTION]);
    $suffix = $fixture->suffix();
    $provider = $person('provider', 'Course', 'Provider', 'STUDENT', $suffix);
    $company = $fixture->createCompany($provider['id'], 'Demo browser provider ' . $suffix, 'demo-browser-' . $suffix . '.example.test');
    $courses = [];
    foreach (['a' => 30000, 'b' => 20000] as $key => $price) {
        $slug = 'demo-browser-' . $key . '-' . $suffix;
        $course = $fixture->createCourse($provider['id'], $company, $slug, 'Demo browser course ' . strtoupper($key) . ' ' . $suffix, 'published');
        $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
        $fixture->createPriceVariant($course, $provider['id'], 2592000, $price, true);
        $courses[$key] = ['id' => $course, 'slug' => $slug];
    }
    $admin = $person('admin', 'Demo', 'Administrator', 'ADMIN', $suffix);
    $learner = $person('learner', 'Thandi', 'Nkosi', 'STUDENT', $suffix);
    $db->executeStatement("UPDATE users SET mobile_number='0821234567' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => 'Thandi Nkosi', 'address_line_1' => '7 Demo Street', 'city' => 'Cape Town', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    $reference = (string) $db->fetchOne('SELECT account_reference FROM users WHERE id = :id', ['id' => $learner['id']]);
    $writeState(['suffix' => $suffix, 'courses' => $courses, 'users' => ['provider' => $provider['id'], 'admin' => $admin['id'], 'learner' => $learner['id']],
        'admin_token' => $session($admin['id'], $admin['email']),
        'learner' => ['id' => $learner['id'], 'email' => $learner['email'], 'token' => $session($learner['id'], $learner['email']), 'reference' => AccountReference::format($reference)]]);
    echo "Created the demo site fixture.\n";
} elseif ($action === 'order') {
    $learner = (int) ($argv[3] ?? 0);
    $records = $container->get(CommerceRepository::class);
    $id = (int) $db->fetchOne('SELECT id FROM commerce_orders WHERE purchaser_user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $learner]);
    if ($id < 1) { echo json_encode(['id' => 0]) . "\n"; exit(0); }
    $snapshot = CommerceRepository::decode((string) $records->order($id)['snapshot']);
    $invoice = null;
    foreach ($records->documents($id) as $document) if ($document['kind'] === 'invoice') $invoice = $document;
    $html = $invoice === null ? '' : $container->get(FinancialDocuments::class)->render($invoice)->html;
    echo json_encode(['id' => $id, 'simulation' => ($snapshot['simulation'] ?? false) === true,
        'invoice_simulation' => str_contains($html, SiteMode::SIMULATION), 'invoice_fake_bank' => str_contains($html, 'FAKE BANK (demo site, do not pay)'),
        'invoice_reference' => str_contains($html, 'Use your account reference ' . AccountReference::format((string) ($snapshot['account_reference'] ?? '')) . ' as the payment reference.')], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'theme') {
    // Learners cannot preview a theme, so the matrix activates each in turn; `restore` puts back the one that was active.
    $state = $readState();
    $themes = $container->get(ThemeManager::class);
    $state['active_theme'] ??= $themes->activeTheme();
    $writeState($state);
    $themes->activate((string) ($argv[3] ?? ''), (int) $state['users']['admin']);
    echo $themes->activeTheme() . "\n";
} elseif ($action === 'restore') {
    $state = $readState();
    if (isset($state['active_theme'])) {
        $container->get(ThemeManager::class)->activate($state['active_theme'], (int) $state['users']['admin']);
        unset($state['active_theme']);
        $writeState($state);
    }
    echo "Restored.\n";
} elseif ($action === 'cleanup') {
    $db->executeStatement('DELETE FROM app_options WHERE option_key = :k', ['k' => SiteMode::OPTION]);
    if (!is_file($statePath)) exit(0);
    $state = $readState();
    if (isset($state['active_theme'])) $container->get(ThemeManager::class)->activate($state['active_theme'], (int) $state['users']['admin']);
    $ids = '{' . implode(',', array_map('intval', array_values($state['users']))) . '}';
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $ids]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[])) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.cart_id = commerce_carts.id)', ['ids' => $ids]);
    foreach ($state['courses'] as $course) $db->executeStatement("UPDATE courses SET status='retired' WHERE id = :c", ['c' => $course['id']]);
    unlink($statePath);
    echo "Removed the sessions, carts and saved site mode; orders and their documents remain.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/demo-site-fixture.php create|order|theme|restore|cleanup [state-path] [learner-id]');
}
