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
    // A learner and a course provider made with its founding administrator, with twelve published courses, every name
    // a real one nobody on the site has (owner, 2026-10-27).
    $learner = $fixture->person('STUDENT');
    $user = $learner['id'];
    $email = $learner['email'];
    $provider = $fixture->company();
    $owner = $provider['founder']['id'];
    $company = $provider['id'];
    $courses = [];
    foreach (range(0, 11) as $i) {
        $courses[] = $id = $fixture->course($owner, $company, 'published')['id'];
        $db->executeStatement("UPDATE courses SET published_at = NOW() - CAST(:age AS INTERVAL) WHERE id = :id", ['age' => $i . ' hours', 'id' => $id]);
        $fixture->createPriceVariant($id, $owner, 31536000, 45000 + 5000 * $i);
    }
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Popular courses browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token, 'owner' => $owner, 'company' => $company, 'courses' => $courses, 'scrub' => $fixture->seeds()], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created popular-courses fixture.\n";
} elseif ($action === 'reset' || $action === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $db->executeStatement('DELETE FROM course_favourites WHERE user_id=:u', ['u' => $state['user']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed popular-courses fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/popular-courses-fixture.php create|reset|cleanup [state-path]');
}
