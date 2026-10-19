<?php

declare(strict_types=1);

// Development-only fixture for commerce-wallets.cjs: three published paid courses from a provider
// company (A R500.00, B R300.00, C R100.00) and an ADMIN with a session. `learner` adds a fresh signed-in
// learner and gives them, only through what a learner and ADMIN do in the app (owner, 2026-10-19: "No
// check may ever write records a real person could not have produced"): their names, cell number and
// billing details at checkout; R200.00 of wallet credit, from an order for course C paid by EFT with
// R300.00, whose overpayment ADMIN then marks dealt with; and course A, assigned by ADMIN (so Start
// course asks for confirmation). It prints the learner's id, token and account reference. The check runs
// on the commerce settings' defaults: `create` keeps the site's saved commerce settings and `cleanup`
// puts them back.
// `order <learner>` prints the learner's latest order (id, state, still due); `wallet <learner>` prints
// the learner's wallet balance in cents; `request <learner>` the learner's open refund request.
// `company-learner` adds a signed-in member of a client company, a company account, which never buys. `cleanup` removes what the database allows and puts back
// the commerce settings it found: orders, payments, refunds and ledger rows are immutable financial
// history and stay behind. The development database is disposable; a reset removes them.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Auth\AclService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\CheckoutService;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Commerce\Application\PaymentAdministrationService;
use CattoLearning\Commerce\Application\TransactionAttention;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Token;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$action = $argv[1] ?? '';
$statePath = $argv[2] ?? '/tmp/catto-commerce-wallets-state.json';
$fixture = new DevelopmentFixture($db);
$readState = static fn(): array => json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
$writeState = static function (array $state) use ($statePath): void {
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
};
$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Commerce wallets browser', $email);
    return $token;
};
// The person as the app knows them once signed in: their roles and what those allow.
$signedIn = static function (int $id) use ($db, $container): CurrentUser {
    $roles = $container->get(RoleRepository::class)->roles($id);
    $email = (string) $db->fetchOne('SELECT email FROM user_emails WHERE user_id = :id AND is_primary', ['id' => $id]);
    return new CurrentUser($id, (string) $db->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $id]), $email, (string) $db->fetchOne('SELECT display_name FROM users WHERE id = :id', ['id' => $id]),
        $roles, $container->get(AclService::class)->permissionsForUser($id, $roles), Uuid::v4(), $email);
};
$person = static function (string $key, string $first, string $last, string $role, string $suffix) use ($fixture, $db, $container): array {
    $email = 'wallet-browser-' . $key . '-' . $suffix . '@example.test';
    $id = $fixture->createUser($first . ' ' . $last, $email);
    $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
    $container->get(RoleRepository::class)->assign($id, $role);
    return ['id' => $id, 'email' => $email];
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous commerce wallets fixture first.');
    $suffix = $fixture->suffix();
    $provider = $person('provider', 'Course', 'Provider', 'STUDENT', $suffix);
    $company = $fixture->createCompany($provider['id'], 'Wallet browser provider ' . $suffix, 'wallet-browser-' . $suffix . '.example.test');
    $courses = [];
    foreach (['a' => 50000, 'b' => 30000, 'c' => 10000] as $key => $price) {
        $slug = 'wallet-browser-' . $key . '-' . $suffix;
        $course = $fixture->createCourse($provider['id'], $company, $slug, 'Wallet browser course ' . strtoupper($key) . ' ' . $suffix, 'published');
        $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
        $courses[$key] = ['id' => $course, 'slug' => $slug, 'variant' => $fixture->createPriceVariant($course, $provider['id'], 2592000, $price, true)];
    }
    $admin = $person('admin', 'Wallets', 'Administrator', 'ADMIN', $suffix);
    // The check runs on the commerce settings' defaults; the owner's saved settings (such as a 3% Stitch
    // fee) are kept in the state and put back by cleanup.
    $saved = $db->fetchAllAssociative("SELECT option_key, option_value, updated_at, updated_by_user_id FROM app_options WHERE option_key LIKE 'commerce.%'");
    $db->executeStatement("DELETE FROM app_options WHERE option_key LIKE 'commerce.%'");
    $writeState(['suffix' => $suffix, 'company' => $company, 'courses' => $courses, 'users' => ['provider' => $provider['id'], 'admin' => $admin['id']], 'saved_settings' => $saved,
        'learners' => [], 'admin_token' => $session($admin['id'], $admin['email'])]);
    echo "Created the commerce wallets fixture.\n";
} elseif ($action === 'learner') {
    $state = $readState();
    $key = 'learner' . (count($state['learners']) + 1);
    $learner = $person($key, 'Thandi', 'Nkosi', 'STUDENT', $state['suffix']);
    $buyer = $signedIn($learner['id']);
    $admin = $signedIn((int) $state['users']['admin']);
    // The learner buys course C by EFT, giving their details at checkout, as the checkout pages do.
    $_SESSION = [];
    $orders = $container->get(OrderService::class);
    $checkout = $container->get(CheckoutService::class);
    $orders->changeCart($buyer, (int) $state['courses']['c']['variant']);
    $checkout->saveProfile($buyer, ['first_name' => 'Thandi', 'last_name' => 'Nkosi', 'mobile_number' => '0821234567', 'billing_name' => 'Thandi Nkosi',
        'address_line_1' => '7 Wallet Street', 'city' => 'Cape Town', 'country_code' => 'ZA']);
    $checkout->selectMethod($buyer, 'eft', '', false);
    $cart = $orders->cart($buyer);
    $paid = (int) $checkout->place($buyer, (int) $cart['id'], (string) $cart['quote'], true, true);
    // ADMIN records R300.00 from the bank against that R100.00 order: the course starts and R200.00 goes
    // to the learner's wallet. ADMIN marks that overpayment dealt with, so the check finds only its own.
    $container->get(PaymentAdministrationService::class)->confirmBank($admin, $paid, Uuid::v4(), 30000, (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
        'BROWSER-' . $state['suffix'] . '-' . $learner['id'], 'Browser check: R300.00 paid for a R100.00 course.');
    $overpayment = (string) $db->fetchOne("SELECT id FROM commerce_ledger_entries WHERE order_id = :o AND kind = 'overpayment_to_wallet'", ['o' => $paid]);
    $container->get(TransactionAttention::class)->dismiss($admin, 'overpaid', $overpayment, 'Browser check: the learner keeps the credit.');
    // ADMIN assigns course A from the person's page.
    $container->get(CourseService::class)->grantToPerson((int) $state['courses']['a']['id'], $learner['id'], 30, $admin->id);
    $state['learners'][] = $learner['id'];
    $writeState($state);
    $reference = (string) $db->fetchOne('SELECT account_reference FROM users WHERE id = :id', ['id' => $learner['id']]);
    echo json_encode(['id' => $learner['id'], 'email' => $learner['email'], 'token' => $session($learner['id'], $learner['email']), 'reference' => AccountReference::format($reference)], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'company-learner') {
    $state = $readState();
    $key = 'staff' . (count($state['learners']) + 1);
    $learner = $person($key, 'Lerato', 'Mokoena', 'STUDENT', $state['suffix']);
    $company = $fixture->createCompany((int) $state['users']['admin'], 'Wallet browser client ' . $key . ' ' . $state['suffix'], 'wallet-client-' . $key . '-' . $state['suffix'] . '.example.test');
    $fixture->addCompanyMember($company, $learner['id']);
    $state['learners'][] = $learner['id'];
    $writeState($state);
    echo json_encode(['id' => $learner['id'], 'token' => $session($learner['id'], $learner['email'])], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'order') {
    $learner = (int) ($argv[3] ?? 0);
    $records = $container->get(CommerceRepository::class);
    $id = (int) $db->fetchOne('SELECT id FROM commerce_orders WHERE purchaser_user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $learner]);
    echo json_encode($id > 0 ? ['id' => $id, 'state' => $records->order($id)['state'], 'due' => $records->dueAmount($id)] : ['id' => 0], JSON_THROW_ON_ERROR) . "\n";
} elseif ($action === 'request') {
    echo (int) $db->fetchOne("SELECT id FROM commerce_refund_requests WHERE requested_by_user_id = :u AND status = 'requested' ORDER BY id DESC LIMIT 1", ['u' => (int) ($argv[3] ?? 0)]) . "\n";
} elseif ($action === 'theme') {
    // Learners cannot preview a theme, so the matrix activates each in turn; `restore` puts back the one that was active.
    $state = $readState();
    $themes = $container->get(\CattoLearning\View\ThemeManager::class);
    $state['active_theme'] ??= $themes->activeTheme();
    $writeState($state);
    $themes->activate((string) ($argv[3] ?? ''), (int) $state['users']['admin']);
    echo $themes->activeTheme() . "\n";
} elseif ($action === 'restore') {
    $state = $readState();
    if (isset($state['active_theme'])) {
        $container->get(\CattoLearning\View\ThemeManager::class)->activate($state['active_theme'], (int) $state['users']['admin']);
        unset($state['active_theme']);
        $writeState($state);
    }
    echo "Restored.\n";
} elseif ($action === 'wallet') {
    echo $container->get(WalletService::class)->balance(WalletOwner::person((int) ($argv[3] ?? 0))) . "\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = $readState();
    $db->executeStatement("DELETE FROM app_options WHERE option_key LIKE 'commerce.%'");
    foreach ($state['saved_settings'] ?? [] as $row) {
        $db->executeStatement('INSERT INTO app_options (option_key, option_value, updated_at, updated_by_user_id) VALUES (:k, :v, :at, :by)',
            ['k' => $row['option_key'], 'v' => $row['option_value'], 'at' => $row['updated_at'], 'by' => $row['updated_by_user_id']]);
    }
    if (isset($state['active_theme'])) $container->get(\CattoLearning\View\ThemeManager::class)->activate($state['active_theme'], (int) $state['users']['admin']);
    $users = [...array_values($state['users']), ...$state['learners']];
    $ids = '{' . implode(',', array_map('intval', $users)) . '}';
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $ids]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[])) AND NOT EXISTS (SELECT 1 FROM commerce_orders o WHERE o.cart_id = commerce_carts.id)', ['ids' => $ids]);
    foreach ($state['courses'] as $course) $db->executeStatement("UPDATE courses SET status='retired' WHERE id = :c", ['c' => $course['id']]);
    unlink($statePath);
    echo "Removed the sessions, carts and setting overrides; orders, the ledger and what they refer to remain.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/commerce-wallets-fixture.php create|learner|company-learner|order|request|wallet|theme|restore|cleanup [state-path] [learner-id]');
}
