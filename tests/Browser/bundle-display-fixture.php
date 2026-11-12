<?php

declare(strict_types=1);

// Development-only fixture for bundle-display.cjs. It changes no bundle and no course: the check reads the site's own
// bundles as a visitor sees them. `create` makes a company with its founding Company Administrator (a company account,
// which is offered no bundle) and an administrator (who may preview each theme), with a session each, and writes what BundleService says the pages should show: the
// course in the most bundles with its notes, every bundle on All courses, the top-level category with the most bundles
// and one with none; `expect` asks again (the check runs it first). `cleanup` removes the company, the three people and everything their browsers recorded.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\CheckScrub;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-bundle-display-state.json';
$action = $argv[1] ?? '';

// What BundleService says each page should show now, asked again at the start of every run (`expect`), since the
// owner may change a bundle between runs: the course in the most bundles a visitor could buy, with the notes its page
// should draw in their order, every bundle on All courses, and the top-level categories with the most bundles and with none.
$expect = static function () use ($container, $db): array {
    $bundles = $container->get(BundleService::class);
    $courses = $container->get(CourseService::class);
    $best = null;
    foreach ($db->fetchFirstColumn("SELECT DISTINCT bc.course_id FROM bundle_courses bc JOIN bundles b ON b.id = bc.bundle_id JOIN courses c ON c.id = bc.course_id WHERE b.status = 'published' AND c.status = 'published' ORDER BY bc.course_id") as $courseId) {
        $notes = $bundles->forCourse((int) $courseId, null);
        if ($best === null || count($notes) > count($best['notes'])) $best = ['id' => (int) $courseId, 'notes' => $notes];
    }
    if ($best === null || $best['notes'] === []) throw new RuntimeException('No published course is in a bundle that can be bought: there is nothing for the check to see.');
    $note = static fn(array $n): array => ['title' => (string) $n['title'], 'href' => (string) $n['href'], 'save' => $n['save_label'] === null ? null : 'Save ' . $n['save_label'], 'others' => (string) $n['others_words']];
    $with = $without = null;
    foreach ($courses->categoryNavigation(null)['subcategories'] as $category) {
        $row = array_column($bundles->catalogueRow($category['id'], null), 'title');
        if ($row !== [] && ($with === null || count($row) > count($with['row']))) $with = ['href' => $category['href'], 'name' => $category['label'], 'row' => $row];
        if ($row === [] && $without === null) $without = ['href' => $category['href'], 'name' => $category['label']];
    }
    return [
        'course' => ['slug' => (string) $db->fetchOne('SELECT slug FROM courses WHERE id = :id', ['id' => $best['id']]), 'notes' => array_map($note, $best['notes'])],
        'all' => array_column($bundles->catalogueRow(null, null), 'title'),
        // The bundles with a drawing of their own, which their cards and pages show (owner, 2026-11-07).
        'drawn' => array_column(array_filter($bundles->catalogueRow(null, null), static fn(array $card): bool => $card['cover_href'] !== null), 'title'),
        'with' => $with,
        'without' => $without,
    ];
};

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous bundle display fixture first.');
    $fixture = new DevelopmentFixture($db);
    $company = $fixture->company();
    $sessions = $container->get(AuthSessionRepository::class);
    $token = Token::generate();
    $sessions->create($company['founder']['id'], Token::hash($token), 7200, '', 'Bundle display browser', $company['founder']['email']);
    // One of its staff, who cannot buy: since companies buy bundles as bundle credits (owner, 2026-11-11) only its Company
    // Administrator is shown them.
    $staff = $fixture->staff($company);
    $staffToken = Token::generate();
    $sessions->create($staff['id'], Token::hash($staffToken), 7200, '', 'Bundle display browser', $staff['email']);
    // An administrator, a private account like any buyer, who may preview each theme (theme_preview) without activating it.
    $admin = $fixture->person('ADMIN');
    $adminToken = Token::generate();
    $sessions->create($admin['id'], Token::hash($adminToken), 7200, '', 'Bundle display browser', $admin['email']);

    $state = $expect() + [
        'company_token' => $token,
        'staff_token' => $staffToken,
        'admin_token' => $adminToken,
        'scrub' => $fixture->seeds(),
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created bundle display fixture.\n";
} elseif ($action === 'expect') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($statePath, json_encode($expect() + $state, JSON_THROW_ON_ERROR));
    echo "Asked the bundle service again.\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) exit(0);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    (new CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the bundle display check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/bundle-display-fixture.php create|expect|cleanup [state-path]');
}
