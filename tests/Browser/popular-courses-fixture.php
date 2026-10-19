<?php

declare(strict_types=1);

// Development-only fixture for popular-courses.cjs: one signed-in learner, so the home page's course
// cards carry favourite stars, and twelve published courses of its own, three groups of four, so the
// home page's Popular Courses rotate whatever else the database holds (owner, 2026-10-19: a clean
// database). `reset` clears the learner's favourites so each scenario starts with every star off;
// `cleanup` removes the learner, the courses and everything they recorded.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-popular-courses-state.json';
$action = $argv[1] ?? '';

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous popular-courses fixture first.');
    $fixture = new DevelopmentFixture($db);
    $email = 'popular-courses-' . $fixture->suffix() . '@example.test';
    $user = $fixture->createUser('Popular courses browser', $email);
    $container->get(RoleRepository::class)->assign($user, 'STUDENT');
    $owner = $fixture->createUser('Popular courses provider', 'popular-courses-owner-' . $fixture->suffix() . '@example.test');
    $company = $fixture->createCompany($owner, 'Popular courses provider ' . $fixture->suffix(), 'popular-courses-' . $fixture->suffix() . '.example.test');
    $courses = [];
    foreach (['Bookkeeping Basics', 'Payroll Essentials', 'Business Writing', 'Time Management', 'Customer Service', 'Excel for Beginners',
        'Project Planning', 'First Aid at Work', 'Fire Safety', 'Leading a Team', 'Sales Conversations', 'Digital Marketing'] as $i => $title) {
        $courses[] = $id = $fixture->createCourse($owner, $company, 'popular-courses-' . ($i + 1) . '-' . $fixture->suffix(), $title, 'published');
        $db->executeStatement("UPDATE courses SET published_at = NOW() - CAST(:age AS INTERVAL) WHERE id = :id", ['age' => $i . ' hours', 'id' => $id]);
        $fixture->createPriceVariant($id, $owner, 31536000, 45000 + 5000 * $i);
    }
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Popular courses browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token, 'owner' => $owner, 'company' => $company, 'courses' => $courses], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created popular-courses fixture.\n";
} elseif ($action === 'reset' || $action === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with((string) $state['email'], 'popular-courses-')) throw new RuntimeException('Unexpected fixture identity.');
    $db->executeStatement('DELETE FROM course_favourites WHERE user_id=:u', ['u' => $state['user']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    $courses = '{' . implode(',', array_map('intval', $state['courses'] ?? [])) . '}';
    foreach (['course_favourites', 'analytics_events', 'course_price_variants'] as $table) {
        $db->executeStatement("DELETE FROM {$table} WHERE course_id = ANY(CAST(:ids AS BIGINT[]))", ['ids' => $courses]);
    }
    $db->executeStatement("DELETE FROM courses WHERE id = ANY(CAST(:ids AS BIGINT[])) AND slug LIKE 'popular-courses-%'", ['ids' => $courses]);
    if ((int) ($state['company'] ?? 0) > 0) $db->executeStatement("DELETE FROM companies WHERE id = :c AND domain LIKE 'popular-courses-%'", ['c' => (int) $state['company']]);
    if ((int) ($state['owner'] ?? 0) > 0) $db->executeStatement("DELETE FROM users WHERE id = :u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'popular-courses-owner-%')", ['u' => (int) $state['owner']]);
    $db->executeStatement('DELETE FROM analytics_events WHERE user_id=:u', ['u' => $state['user']]);
    $db->executeStatement('DELETE FROM audit_log WHERE user_id=:u', ['u' => $state['user']]);
    $db->executeStatement("DELETE FROM users WHERE id=:u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id=users.id AND email LIKE 'popular-courses-%@example.test')", ['u' => $state['user']]);
    unlink($statePath);
    echo "Removed popular-courses fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/popular-courses-fixture.php create|reset|cleanup [state-path]');
}
