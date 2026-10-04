<?php

declare(strict_types=1);

// Development-only fixture for course-reviews.cjs: a published course, a learner entitled to it, a
// second learner whose review is already approved, and an ADMIN to moderate. `reset` removes the
// first learner's review so each run starts from no review; `cleanup` removes everything it created.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseReviewRepository;
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
    $suffix = $fixture->suffix();
    $session = static function (int $user, string $email) use ($container): string {
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Course review browser', $email);
        return $token;
    };
    $adminEmail = 'course-review-admin-' . $suffix . '@example.test';
    $admin = $fixture->createUser('Course review admin', $adminEmail);
    $container->get(RoleRepository::class)->assign($admin, 'ADMIN');
    $company = $fixture->createCompany($admin, 'Course review ' . $suffix, 'course-review-' . $suffix . '.example.test');
    $slug = 'course-review-' . $suffix;
    $course = $fixture->createCourse($admin, $company, $slug, 'Reviewed in the browser', 'published');
    $learners = [];
    foreach (['learner' => ['Jane', 'Doe'], 'reviewer' => ['Sam', 'Smith']] as $key => [$first, $last]) {
        $email = 'course-review-' . $key . '-' . $suffix . '@example.test';
        $user = $fixture->createUser($first . ' ' . $last, $email);
        $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $user]);
        $container->get(RoleRepository::class)->assign($user, 'STUDENT');
        $fixture->createEnrolment($user, $course, $admin, 31536000, false, 'active');
        $learners[$key] = ['user' => $user, 'email' => $email];
    }
    $reviews = $container->get(CourseReviewService::class);
    $reviews->submit($learners['reviewer']['user'], $course, 4, 'Already approved before the run.');
    $approved = $container->get(CourseReviewRepository::class)->forLearner($course, $learners['reviewer']['user']) ?? [];
    $reviews->approve((int) $approved['id'], $admin, $approved['revision'], null);
    $state = [
        'suffix' => $suffix, 'slug' => $slug, 'course' => $course, 'company' => $company,
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
    if (!str_starts_with((string) $state['slug'], 'course-review-')) throw new RuntimeException('Unexpected fixture course.');
    $db->executeStatement('DELETE FROM course_reviews WHERE course_id=:c AND user_id=:u', ['c' => $state['course'], 'u' => $state['learner']]);
    if ($action === 'reset') {
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        exit;
    }
    $users = [$state['admin'], $state['learner'], $state['reviewer']];
    $db->executeStatement('DELETE FROM analytics_events WHERE course_id=:c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM course_reviews WHERE course_id=:c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM course_enrolments WHERE course_id=:c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM courses WHERE id=:c', ['c' => $state['course']]);
    $db->executeStatement('DELETE FROM companies WHERE id=:c', ['c' => $state['company']]);
    foreach ($users as $user) {
        $db->executeStatement('DELETE FROM analytics_events WHERE user_id=:u', ['u' => $user]);
        $db->executeStatement('DELETE FROM audit_log WHERE user_id=:u', ['u' => $user]);
        $db->executeStatement("DELETE FROM users WHERE id=:u AND EXISTS (SELECT 1 FROM user_emails WHERE user_id=users.id AND email LIKE 'course-review-%@example.test')", ['u' => $user]);
    }
    unlink($statePath);
    echo "Removed course review fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/course-review-fixture.php create|reset|cleanup [state-path]');
}
