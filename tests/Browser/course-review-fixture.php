<?php

declare(strict_types=1);

// Development-only fixture for course-reviews.cjs: a published course, a learner entitled to it, a
// second learner whose review is already approved, and an ADMIN to moderate. `reset` removes the
// first learner's review so each run starts from no review; `cleanup` removes everything it created.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseReviewRepository;
use CattoLearning\Course\CourseService;
use CattoLearning\Course\LearningService;
use CattoLearning\Course\CourseReviewService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$boot = CliBootstrap::boot();
$container = $boot['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-course-review-state.json';
$action = $argv[1] ?? '';

if ($action === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous review fixture first.');
    $fixture = new DevelopmentFixture($db);
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Course review browser', $email);
        return $token;
    };
    // An administrator, a course from a provider made with its founding administrator, and two learners on it, every
    // name a real one nobody on the site has (owner, 2026-10-27).
    $adminPerson = $fixture->person('ADMIN');
    $admin = $adminPerson['id'];
    $adminEmail = $adminPerson['email'];
    $provider = $fixture->company();
    $made = $fixture->course($provider['founder']['id'], $provider['id'], 'published');
    $course = $made['id'];
    $slug = $made['slug'];
    $learners = [];
    foreach (['learner', 'reviewer'] as $key) {
        $person = $fixture->person('STUDENT');
        // ADMIN assigns the course and the learner starts it, as in the app (owner, 2026-10-19).
        $container->get(CourseService::class)->grantToPerson($course, $person['id'], 365, $admin);
        $container->get(LearningService::class)->start($person['id'], $slug);
        $learners[$key] = ['user' => $person['id'], 'email' => $person['email'], 'name' => $person['name']];
    }
    $reviews = $container->get(CourseReviewService::class);
    $reviews->submit($learners['reviewer']['user'], $course, 4, 'Clear and practical. The worked examples helped most.');
    $approved = $container->get(CourseReviewRepository::class)->forLearner($course, $learners['reviewer']['user']) ?? [];
    $reviews->approve((int) $approved['id'], $admin, $approved['revision'], null);
    $state = [
        'slug' => $slug, 'course' => $course, 'course_title' => $made['title'], 'company' => $provider['id'], 'scrub' => $fixture->seeds(), 'learner_name' => $learners['learner']['name'], 'reviewer_name' => $learners['reviewer']['name'], 'learner_public' => (string) $db->fetchOne("SELECT first_name || ' ' || left(last_name, 1) || '.' FROM users WHERE id = :u", ['u' => $learners['learner']['user']]),
        'reviewer_public' => (string) $db->fetchOne("SELECT first_name || ' ' || left(last_name, 1) || '.' FROM users WHERE id = :u", ['u' => $learners['reviewer']['user']]),
        'admin' => $admin, 'admin_email' => $adminEmail, 'admin_token' => $session($admin, $adminEmail),
        'learner' => $learners['learner']['user'], 'learner_email' => $learners['learner']['email'],
        'learner_token' => $session($learners['learner']['user'], $learners['learner']['email']),
        'reviewer' => $learners['reviewer']['user'],
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created course review fixture.\n";
} elseif ($action === 'reset' || $action === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $db->executeStatement('DELETE FROM course_reviews WHERE course_id=:c AND user_id=:u', ['c' => $state['course'], 'u' => $state['learner']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed course review fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/course-review-fixture.php create|reset|cleanup [state-path]');
}
