<?php

declare(strict_types=1);

// Development-only fixture for landing-pages.cjs. `create` makes a provider company and two published
// courses. Course A sells for R1 500 a year or R900 for six months, has an outline of two sections
// (one item in the free preview, an assessment whose question must never reach a landing page) and
// one approved review; its landing page is made by the browser check. Course B has a published
// landing page made here, and no active price: it is not on sale. It also makes an ADMIN, a course
// owner who manages course A without publishing rights, a learner who has course A and a learner
// who does not, each with sessions. `cleanup` removes all of it: the check places no order. The
// development database is disposable; a reset removes anything left behind.

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseService;
use CattoLearning\Course\Landing\LandingPageService;
use CattoLearning\Course\LearningService;
use CattoLearning\Course\CourseReviewRepository;
use CattoLearning\Course\CourseReviewService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = CliBootstrap::boot()['container'];
$db = $container->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-landing-pages-state.json';
$action = $argv[1] ?? '';
$fixture = new DevelopmentFixture($db);

$session = static function (int $user, string $email) use ($container): string {
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Landing pages browser', $email);
    return $token;
};
// A believable person nobody on the site shares a name with (owner, 2026-10-27).
$person = static function (array $roles) use ($fixture, $container): array {
    $person = $fixture->person('STUDENT');
    foreach ($roles as $role) $container->get(RoleRepository::class)->assign($person['id'], $role);
    return $person;
};
// A property course title no course has: the fixture's lessons are about deeds and bonds.
$title = static function () use ($db): array {
    foreach (['Property Transfers in Practice', 'Conveyancing Essentials', 'Deeds Office Procedures', 'Sectional Title Basics', 'Bond Registration Explained', 'Transfer Duty and Costs'] as $candidate) {
        $slug = trim((string) preg_replace('/[^a-z]+/', '-', strtolower($candidate)), '-');
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM courses WHERE lower(title) = lower(:t) OR slug = :s', ['t' => $candidate, 's' => $slug]) === 0) return ['title' => $candidate, 'slug' => $slug];
    }
    throw new RuntimeException('Every property course title is in use.');
};
$node = static fn(int $course, ?int $parent, int $position, string $type): int => (int) $db->fetchOne(
    'INSERT INTO course_structure_nodes (public_id,course_id,parent_node_id,position,node_type) VALUES (:p,:c,:parent,:position,:type) RETURNING id',
    ['p' => Uuid::v4(), 'c' => $course, 'parent' => $parent, 'position' => $position, 'type' => $type]
);
$item = static fn(int $owner, string $key, string $type, string $title, string $description, string $content): int => (int) $db->fetchOne(
    'INSERT INTO course_items (public_id,item_key,item_type,title,description_html,content_source,created_by_user_id,updated_by_user_id) VALUES (:p,:k,:t,:title,:d,:content,:u,:u) RETURNING id',
    ['p' => Uuid::v4(), 'k' => $key, 't' => $type, 'title' => $title, 'd' => $description, 'content' => $content, 'u' => $owner]
);
$place = static fn(int $node, int $course, int $item, bool $preview) => $db->executeStatement(
    'INSERT INTO course_item_placements (node_id,course_id,course_item_id,public_preview) VALUES (:n,:c,:i,:p)',
    ['n' => $node, 'c' => $course, 'i' => $item, 'p' => $preview]
);
$price = static fn(int $course, int $owner, int $position, int $seconds, int $minor, bool $default) => $db->executeStatement(
    "INSERT INTO course_price_variants (public_id,course_id,access_period_seconds,price_minor_units,currency_code,position,is_active,is_default,created_by_user_id,updated_by_user_id) VALUES (:p,:c,:s,:m,'ZAR',:position,TRUE,:d,:u,:u)",
    ['p' => Uuid::v4(), 'c' => $course, 's' => $seconds, 'm' => $minor, 'position' => $position, 'd' => $default, 'u' => $owner]
);

if ($action === 'create') {
    if (is_file($statePath)) {
        throw new RuntimeException('Clean up the previous landing pages fixture first.');
    }
    // A course provider made with its founding administrator (the courses' owner), an administrator and three learners.
    $provider = $fixture->company();
    $owner = $provider['founder'];
    $container->get(RoleRepository::class)->assign($owner['id'], 'COURSE_OWNER');
    $company = $provider['id'];
    $admin = $person(['ADMIN']);
    $learner = $person([]);
    $holder = $person([]);
    $reviewer = $person([]);

    $a = $title();
    $a['id'] = $fixture->createCourse($owner['id'], $company, $a['slug'], $a['title'], 'published');
    $db->executeStatement("UPDATE courses SET published_at=NOW(), subtitle='Property transfers from offer to registration', summary='A practical course on transferring property in South Africa.', certificate_accreditation='LPC CPD: 4 points', level='Intermediate' WHERE id=:id", ['id' => $a['id']]);
    $price($a['id'], $owner['id'], 1, 31536000, 150000, true);
    $price($a['id'], $owner['id'], 2, 15552000, 90000, false); // 6 months: 180 days (a month is 30 days)
    $deeds = $node($a['id'], null, 1, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Deeds and transfers')", ['n' => $deeds]);
    $place($node($a['id'], $deeds, 1, 'item'), $a['id'], $item($owner['id'], $a['slug'] . '-lesson', 'html_lesson', 'Drafting a deed of transfer', '<p>How a deed is drafted.</p>', '<p>SECRET BROWSER LESSON</p>'), true);
    $test = $item($owner['id'], $a['slug'] . '-test', 'assessment', 'Deeds test', '', '');
    $db->executeStatement('INSERT INTO course_item_assessments (course_item_id) VALUES (:i)', ['i' => $test]);
    $db->executeStatement("INSERT INTO assessment_questions (public_id,course_item_id,position,question_html) VALUES (:p,:i,1,'SECRET BROWSER QUESTION')", ['p' => Uuid::v4(), 'i' => $test]);
    $place($node($a['id'], $deeds, 2, 'item'), $a['id'], $test, false);
    $bonds = $node($a['id'], null, 2, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Bonds')", ['n' => $bonds]);
    $place($node($a['id'], $bonds, 1, 'item'), $a['id'], $item($owner['id'], $a['slug'] . '-bonds', 'html_lesson', 'Registering a bond', '', '<p>SECRET BROWSER BONDS</p>'), false);
    // The holder and the reviewer have course A as a person gets it (owner, 2026-10-19: "No check may ever
    // write records a real person could not have produced"): ADMIN assigns it and they start it. Only a
    // learner with the course may review it, and ADMIN approves the review.
    foreach ([$holder, $reviewer] as $who) {
        $container->get(CourseService::class)->grantToPerson($a['id'], $who['id'], 365, $admin['id']);
        $container->get(LearningService::class)->start($who['id'], $a['slug']);
    }
    $reviews = $container->get(CourseReviewService::class);
    $reviews->submit($reviewer['id'], $a['id'], 5, 'Clear and practical.');
    $submitted = $container->get(CourseReviewRepository::class)->forLearner($a['id'], $reviewer['id']) ?? [];
    $reviews->approve((int) $submitted['id'], $admin['id'], $submitted['revision'], null);

    // Course B: published, its landing page published, and no active price.
    $b = $title();
    $b['id'] = $fixture->createCourse($owner['id'], $company, $b['slug'], $b['title'], 'published');
    $landing = $container->get(LandingPageService::class);
    $landing->create($b['id'], $admin['id']);
    foreach ($landing->page($b['id'])['sections'] as $section) {
        $input = match ($section['section_type']) {
            'hero' => ['headline' => 'A course that is not on sale', 'image' => 'none', 'secondary' => 'none'],
            'audience' => ['items' => 'Anyone curious'],
            'outcomes' => ['items' => 'Know what is on offer'],
            'benefits' => ['items' => [['title' => 'Clarity', 'text' => 'Nothing to buy.']]],
            'faq' => ['items' => [['question' => 'Can I buy it?', 'answer' => 'Not at the moment.']]],
            default => null,
        };
        if ($input !== null) {
            $landing->updateSection($b['id'], $section['id'], $input, $admin['id']);
        }
    }
    $landing->publish($b['id'], $admin['id']);

    $state = [
        'company' => $company,
        'scrub' => $fixture->seeds(),
        'courses' => ['a' => $a, 'b' => $b],
        'users' => ['owner' => $owner['id'], 'admin' => $admin['id'], 'learner' => $learner['id'], 'holder' => $holder['id'], 'reviewer' => $reviewer['id']],
        'tokens' => [
            'admin' => $session($admin['id'], $admin['email']),
            'owner' => $session($owner['id'], $owner['email']),
            'learner' => $session($learner['id'], $learner['email']),
            'holder' => $session($holder['id'], $holder['email']),
        ],
    ];
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created the landing pages fixture.\n";
} elseif ($action === 'cleanup') {
    if (!is_file($statePath)) {
        exit(0);
    }
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    // Everything the check made, the courses' lessons, landing pages, carts and reviews included (owner, 2026-10-27).
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed the landing pages fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/landing-pages-fixture.php create|cleanup [state-path]');
}
