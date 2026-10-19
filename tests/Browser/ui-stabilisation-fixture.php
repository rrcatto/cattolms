<?php

declare(strict_types=1);

// Development-only browser identity. Never changes the active theme or existing accounts. The UI matrix
// pages through People without JavaScript to a third page of 50, so the identity adds 110 people the way ADMIN does (Add
// person), and cleanup removes them: the check does not lean on whatever the database holds, and makes
// only records a person could make (owner, 2026-10-19). For the same reason it publishes a course of its
// own with one lesson in the free preview, which the Bootstrap safety net reads in Core's reader.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$boot = CliBootstrap::boot();
$db = $boot['container']->get(Database::class);
$statePath = $argv[2] ?? '/tmp/catto-ui-stabilisation-state.json';
if (($argv[1] ?? '') === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous browser fixture first.');
    $fixture = new DevelopmentFixture($db);
    $email = 'ui-stabilisation-' . $fixture->suffix() . '@example.test';
    $user = $fixture->createUser('UI stabilisation browser check', $email);
    $boot['container']->get(RoleRepository::class)->assign($user, 'ADMIN');
    // The Company workspace (/company) needs a company to administer; there is no default company (no
    // System Company), so this ADMIN administers a test company of its own.
    $company = $fixture->createCompany($user, 'UI stabilisation browser company', 'ui-stabilisation-' . $fixture->suffix() . '.example.test');
    $fixture->addCompanyMember($company, $user, 'administrator');
    // A published course with a section whose one lesson is in the free preview, as ADMIN builds it in
    // Course Content (a section, a lesson, Public preview ticked).
    $slug = 'ui-stabilisation-reader-' . $fixture->suffix();
    $course = $fixture->createCourse($user, $company, $slug, 'Reading in every theme', 'published');
    $db->executeStatement('UPDATE courses SET published_at = NOW() WHERE id = :id', ['id' => $course]);
    $node = static fn(?int $parent, int $position, string $type): int => (int) $db->fetchOne(
        'INSERT INTO course_structure_nodes (public_id,course_id,parent_node_id,position,node_type) VALUES (:p,:c,:parent,:position,:type) RETURNING id',
        ['p' => Uuid::v4(), 'c' => $course, 'parent' => $parent, 'position' => $position, 'type' => $type]);
    $section = $node(null, 1, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Getting started')", ['n' => $section]);
    $lesson = (int) $db->fetchOne("INSERT INTO course_items (public_id,item_key,item_type,title,description_html,content_source,created_by_user_id,updated_by_user_id)
        VALUES (:p,:k,'html_lesson','What this course covers','','<p>Each theme draws this lesson in Core''s reader.</p>',:u,:u) RETURNING id",
        ['p' => Uuid::v4(), 'k' => 'ui-stabilisation-lesson-' . $fixture->suffix(), 'u' => $user]);
    $db->executeStatement('INSERT INTO course_item_placements (node_id,course_id,course_item_id,public_preview) VALUES (:n,:c,:i,TRUE)', ['n' => $node($section, 1, 'item'), 'c' => $course, 'i' => $lesson]);
    $people = [];
    $first = ['Ayanda', 'Bongani', 'Chantal', 'Dineo', 'Elize', 'Farouk', 'Gugu', 'Hennie', 'Imran', 'Johanna', 'Karabo'];
    $last = ['Botha', 'Cele', 'Dlamini', 'Essop', 'Fourie', 'Gumede', 'Hendricks', 'Ismail', 'Jacobs', 'Khumalo'];
    foreach ($first as $i => $given) {
        foreach ($last as $j => $family) {
            $people[] = $boot['container']->get(PlatformAdministrationService::class)->createPerson(['first_name' => $given, 'last_name' => $family,
                'email' => 'ui-stabilisation-person-' . ($i * 10 + $j + 1) . '-' . $fixture->suffix() . '@example.test', 'role' => 'STUDENT'], $user);
        }
    }
    $token = Token::generate();
    $boot['container']->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'UI stabilisation browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token, 'company' => $company, 'people' => $people, 'course' => $course, 'lesson' => $lesson], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created isolated browser identity.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with($state['email'], 'ui-stabilisation-')) throw new RuntimeException('Unexpected fixture identity.');
    if ((int) ($state['course'] ?? 0) > 0) {
        foreach (['analytics_events', 'course_enrolments', 'course_edit_history'] as $table) {
            $db->executeStatement("DELETE FROM {$table} WHERE course_id = :c", ['c' => (int) $state['course']]);
        }
        $db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id = :c', ['c' => (int) $state['course']]);
        $db->executeStatement("DELETE FROM course_items WHERE id = :i AND item_key LIKE 'ui-stabilisation-lesson-%'", ['i' => (int) $state['lesson']]);
        $db->executeStatement("DELETE FROM courses WHERE id = :c AND slug LIKE 'ui-stabilisation-reader-%'", ['c' => (int) $state['course']]);
    }
    foreach ($state['people'] ?? [] as $person) {
        $db->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => (int) $person]);
        $db->executeStatement("DELETE FROM users WHERE id = :id AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email LIKE 'ui-stabilisation-person-%')", ['id' => (int) $person]);
    }
    $db->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $state['user']]);
    $db->executeStatement('DELETE FROM users WHERE id = :id AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email = :email)', ['id' => $state['user'], 'email' => $state['email']]);
    if ((int) ($state['company'] ?? 0) > 0) $db->executeStatement("DELETE FROM companies WHERE id = :id AND domain LIKE 'ui-stabilisation-%'", ['id' => (int) $state['company']]);
    unlink($statePath);
    echo "Removed browser identity and its activity.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/ui-stabilisation-fixture.php create|cleanup [state-path]');
}
