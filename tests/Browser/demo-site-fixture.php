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
use CattoLearning\Tests\Support\CheckScrub;
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
if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous demo site fixture first.');
    // The check starts from the .env site mode; a site mode the owner saved in Settings is put back by cleanup.
    $savedMode = $db->fetchAssociative('SELECT option_key, option_value, updated_at, updated_by_user_id FROM app_options WHERE option_key = :k', ['k' => SiteMode::OPTION]) ?: null;
    $db->executeStatement('DELETE FROM app_options WHERE option_key = :k', ['k' => SiteMode::OPTION]);
    // A believable course provider (a company made with its founding administrator), two of its courses on sale, an
    // administrator and a private learner, every name one nobody on the site has (owner, 2026-10-27).
    $provider = $fixture->company();
    $courses = [];
    foreach (['a' => 30000, 'b' => 20000] as $key => $price) {
        $course = $fixture->course($provider['founder']['id'], $provider['id'], 'published');
        $fixture->createPriceVariant($course['id'], $provider['founder']['id'], 2592000, $price, true);
        $courses[$key] = ['id' => $course['id'], 'slug' => $course['slug']];
    }
    $admin = $fixture->person('ADMIN');
    $learner = $fixture->person('STUDENT');
    $db->executeStatement("UPDATE users SET mobile_number='0821234567' WHERE id=:id", ['id' => $learner['id']]);
    (new BillingProfileRepository($db))->saveForUser($learner['id'], BillingDetails::forPerson(['billing_name' => $learner['name'], 'address_line_1' => '7 Bree Street', 'city' => 'Cape Town', 'country_code' => 'ZA']), (new DateTimeImmutable())->format(DATE_ATOM));
    $reference = (string) $db->fetchOne('SELECT account_reference FROM users WHERE id = :id', ['id' => $learner['id']]);
    $writeState(['saved_mode' => $savedMode, 'courses' => $courses, 'users' => ['provider' => $provider['founder']['id'], 'admin' => $admin['id'], 'learner' => $learner['id']], 'scrub' => $fixture->seeds(),
        'admin_token' => $session($admin['id'], $admin['email']),
        'learner' => ['id' => $learner['id'], 'email' => $learner['email'], 'token' => $session($learner['id'], $learner['email']), 'reference' => AccountReference::format($reference)]]);
    echo "Created the demo site fixture.\n";
} elseif ($action === 'follows') {
    // What the learner agreed to be emailed about (mailing, step 1): [kind, subject id, source] of each yes.
    $learner = (int) ($argv[3] ?? 0);
    echo json_encode(array_map(static fn(array $r): array => [$r['kind'], $r['subject_id'] === null ? null : (int) $r['subject_id'], $r['source']], $db->fetchAllAssociative("SELECT l.kind, l.subject_id, s.source FROM mailing_subscriptions s JOIN mailing_lists l ON l.id = s.list_id WHERE s.user_id = :u AND s.status = 'subscribed' ORDER BY l.kind, l.subject_id", ['u' => $learner])), JSON_THROW_ON_ERROR) . "\n";
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
    if (!is_file($statePath)) exit(0);
    $state = $readState();
    $db->executeStatement('DELETE FROM app_options WHERE option_key = :k', ['k' => SiteMode::OPTION]);
    if (is_array($state['saved_mode'] ?? null)) {
        $db->executeStatement('INSERT INTO app_options (option_key, option_value, updated_at, updated_by_user_id) VALUES (:k, :v, :at, :by)',
            ['k' => $state['saved_mode']['option_key'], 'v' => $state['saved_mode']['option_value'], 'at' => $state['saved_mode']['updated_at'], 'by' => $state['saved_mode']['updated_by_user_id']]);
    }
    if (isset($state['active_theme'])) $container->get(ThemeManager::class)->activate($state['active_theme'], (int) $state['users']['admin']);
    // Everything the check made, its orders and their money records included (owner, 2026-10-27: "you can remove the data
    // without worrying about the rules").
    (new CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the demo site check made and put the site mode back.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/demo-site-fixture.php create|order|theme|restore|cleanup [state-path] [learner-id]');
}
