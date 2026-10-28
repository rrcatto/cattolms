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
    return $state;
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous legal-pages fixture first.');
    $fixture = new DevelopmentFixture($db);
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Legal pages browser', $email);
        return $token;
    };
    // A course provider and a client company, each made with its founding Company Administrator, a private learner and
    // an administrator, every name one nobody on the site has (owner, 2026-10-27).
    $provider = $fixture->company();
    $made = $fixture->course($provider['founder']['id'], $provider['id'], 'published');
    $variant = $fixture->createPriceVariant($made['id'], $provider['founder']['id'], 2592000, 12345, true);
    $learner = $fixture->person('STUDENT');
    $client = $fixture->company();
    $companyAdmin = $client['founder'];
    $admin = $fixture->person('ADMIN');
    $state = [
        'slug' => $made['slug'], 'course' => $made['id'], 'course_title' => $made['title'], 'variant' => $variant,
        'company' => $client['id'], 'provider_company' => $provider['id'], 'theme' => $themes->activeTheme(), 'scrub' => $fixture->seeds(),
        'users' => ['provider' => $provider['founder']['id'], 'learner' => $learner['id'], 'company_admin' => $companyAdmin['id'], 'admin' => $admin['id']],
        'learner_name' => $learner['name'], 'learner_first' => $learner['first'], 'learner_last' => $learner['last'], 'company_name' => $client['name'], 'learner_token' => $session($learner['id'], $learner['email']),
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
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed legal-pages fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/legal-pages-fixture.php create|theme <key>|restore|orders|cleanup');
}
