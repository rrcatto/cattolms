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
use CattoLearning\Course\Landing\LandingPageService;
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
$person = static function (string $key, string $first, string $last, array $roles, string $suffix) use ($fixture, $db, $container): array {
    $email = 'landing-browser-' . $key . '-' . $suffix . '@example.test';
    $id = $fixture->createUser($first . ' ' . $last, $email);
    $db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $id]);
    foreach ($roles as $role) {
        $container->get(RoleRepository::class)->assign($id, $role);
    }
    return ['id' => $id, 'email' => $email];
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
    $s = $fixture->suffix();
    $owner = $person('owner', 'Thandi', 'Owner', ['COURSE_OWNER'], $s);
    $admin = $person('admin', 'Landing', 'Administrator', ['ADMIN'], $s);
    $learner = $person('learner', 'Sipho', 'Ndlovu', ['STUDENT'], $s);
    $holder = $person('holder', 'Lerato', 'Mokoena', ['STUDENT'], $s);
    $reviewer = $person('reviewer', 'Johan', 'Botha', ['STUDENT'], $s);
    $company = $fixture->createCompany($owner['id'], 'Landing Provider ' . $s, 'landing-browser-' . $s . '.example.test');

    $a = ['slug' => 'landing-browser-a-' . $s, 'title' => 'Landing browser course ' . $s];
    $a['id'] = $fixture->createCourse($owner['id'], $company, $a['slug'], $a['title'], 'published');
    $db->executeStatement("UPDATE courses SET published_at=NOW(), subtitle='Property transfers from offer to registration', summary='A practical course on transferring property in South Africa.', certificate_accreditation='LPC CPD: 4 points', level='Intermediate' WHERE id=:id", ['id' => $a['id']]);
    $price($a['id'], $owner['id'], 1, 31536000, 150000, true);
    $price($a['id'], $owner['id'], 2, 15552000, 90000, false);
    $deeds = $node($a['id'], null, 1, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Deeds and transfers')", ['n' => $deeds]);
    $place($node($a['id'], $deeds, 1, 'item'), $a['id'], $item($owner['id'], 'landing-browser-lesson-' . $s, 'html_lesson', 'Drafting a deed of transfer', '<p>How a deed is drafted.</p>', '<p>SECRET BROWSER LESSON</p>'), true);
    $test = $item($owner['id'], 'landing-browser-test-' . $s, 'assessment', 'Deeds test', '', '');
    $db->executeStatement('INSERT INTO course_item_assessments (course_item_id) VALUES (:i)', ['i' => $test]);
    $db->executeStatement("INSERT INTO assessment_questions (public_id,course_item_id,position,question_html) VALUES (:p,:i,1,'SECRET BROWSER QUESTION')", ['p' => Uuid::v4(), 'i' => $test]);
    $place($node($a['id'], $deeds, 2, 'item'), $a['id'], $test, false);
    $bonds = $node($a['id'], null, 2, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Bonds')", ['n' => $bonds]);
    $place($node($a['id'], $bonds, 1, 'item'), $a['id'], $item($owner['id'], 'landing-browser-bonds-' . $s, 'html_lesson', 'Registering a bond', '', '<p>SECRET BROWSER BONDS</p>'), false);
    $db->executeStatement(
        "INSERT INTO course_reviews (course_id,user_id,rating,comment,status,published_rating,published_comment,published_at,submitted_at,updated_at) VALUES (:c,:u,5,'Clear and practical.','approved',5,'Clear and practical.',NOW(),NOW(),NOW())",
        ['c' => $a['id'], 'u' => $reviewer['id']]
    );
    $db->executeStatement(
        "INSERT INTO course_enrolments (public_id,user_id,course_id,status,access_period_seconds,assigned_by_user_id,is_preview,started_at) VALUES (:p,:u,:c,'active',31536000,:a,FALSE,NOW())",
        ['p' => Uuid::v4(), 'u' => $holder['id'], 'c' => $a['id'], 'a' => $admin['id']]
    );

    // Course B: published, its landing page published, and no active price.
    $b = ['slug' => 'landing-browser-b-' . $s, 'title' => 'Landing browser closed course ' . $s];
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
        'suffix' => $s,
        'company' => $company,
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
    $users = '{' . implode(',', array_map('intval', $state['users'])) . '}';
    $courses = '{' . implode(',', array_map(static fn(array $c): int => (int) $c['id'], $state['courses'])) . '}';
    $db->executeStatement('DELETE FROM auth_sessions WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $users]);
    $db->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $users]);
    // Guests' carts live in their sessions; a cart row naming these prices would block their removal.
    $db->executeStatement('DELETE FROM commerce_cart_items WHERE variant_id IN (SELECT id FROM course_price_variants WHERE course_id = ANY(CAST(:ids AS BIGINT[])))', ['ids' => $courses]);
    $items = $db->fetchFirstColumn('SELECT DISTINCT course_item_id FROM course_item_placements WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courses]);
    $db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courses]);
    foreach ($items as $id) {
        $db->executeStatement('DELETE FROM course_items WHERE id = :id', ['id' => (int) $id]);
    }
    foreach (['analytics_events', 'course_reviews', 'course_enrolments', 'course_landing_pages', 'course_edit_history', 'course_price_variants'] as $table) {
        $db->executeStatement("DELETE FROM {$table} WHERE course_id = ANY(CAST(:ids AS BIGINT[]))", ['ids' => $courses]);
    }
    $db->executeStatement('DELETE FROM courses WHERE id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $courses]);
    $db->executeStatement('DELETE FROM company_users WHERE company_id = :c', ['c' => $state['company']]);
    $db->executeStatement('DELETE FROM companies WHERE id = :c', ['c' => $state['company']]);
    foreach (['analytics_events', 'audit_log', 'course_enrolments', 'user_roles', 'user_emails'] as $table) {
        $db->executeStatement("DELETE FROM {$table} WHERE user_id = ANY(CAST(:ids AS BIGINT[]))", ['ids' => $users]);
    }
    $db->executeStatement('DELETE FROM users WHERE id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => $users]);
    unlink($statePath);
    echo "Removed the landing pages fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/landing-pages-fixture.php create|cleanup [state-path]');
}
