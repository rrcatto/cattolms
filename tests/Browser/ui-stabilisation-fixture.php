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
    // The Company workspace (/company) needs a company to administer; there is no default company (no System Company), so
    // this ADMIN is the founding Company Administrator of a believable company of its own (owner, 2026-10-27).
    $made = $fixture->company();
    $company = $made['id'];
    $user = $made['founder']['id'];
    $email = $made['founder']['email'];
    $boot['container']->get(RoleRepository::class)->assign($user, 'ADMIN');
    // A published course with a section whose one lesson is in the free preview, as ADMIN builds it in
    // Course Content (a section, a lesson, Public preview ticked).
    $reader = $fixture->course($user, $company, 'published');
    $course = $reader['id'];
    $node = static fn(?int $parent, int $position, string $type): int => (int) $db->fetchOne(
        'INSERT INTO course_structure_nodes (public_id,course_id,parent_node_id,position,node_type) VALUES (:p,:c,:parent,:position,:type) RETURNING id',
        ['p' => Uuid::v4(), 'c' => $course, 'parent' => $parent, 'position' => $position, 'type' => $type]);
    $section = $node(null, 1, 'section');
    $db->executeStatement("INSERT INTO course_sections (node_id,title) VALUES (:n,'Getting started')", ['n' => $section]);
    $lesson = (int) $db->fetchOne("INSERT INTO course_items (public_id,item_key,name,item_type,title,description_html,content_source,created_by_user_id,updated_by_user_id)
        VALUES (:p,:k,:k,'html_lesson','What this course covers','','<p>Each theme draws this lesson in Core''s reader.</p>',:u,:u) RETURNING id",
        ['p' => Uuid::v4(), 'k' => $reader['slug'] . '-overview', 'u' => $user]);
    $db->executeStatement('INSERT INTO course_item_placements (node_id,course_id,course_item_id,public_preview) VALUES (:n,:c,:i,TRUE)', ['n' => $node($section, 1, 'item'), 'c' => $course, 'i' => $lesson]);
    // 110 people added as ADMIN adds them, each a different real name (owner, 2026-10-27: never the same name twice).
    $people = [];
    $names = new \CattoLearning\Tests\Support\CheckNames($db);
    foreach (range(1, 110) as $ignored) {
        ['first' => $given, 'last' => $family] = $names->person();
        $people[] = $boot['container']->get(PlatformAdministrationService::class)->createPerson(['first_name' => $given, 'last_name' => $family,
            'email' => strtolower(str_replace(' ', '', $given) . '.' . str_replace(' ', '', $family)) . '@inbox.test', 'role' => 'STUDENT'], $user);
    }
    $token = Token::generate();
    $boot['container']->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'UI stabilisation browser', $email);
    file_put_contents($statePath, json_encode(['user' => $user, 'email' => $email, 'token' => $token, 'company' => $company, 'people' => $people, 'course' => $course, 'lesson' => $lesson, 'scrub' => ['users' => [...$fixture->seeds()['users'], ...$people]] + $fixture->seeds()], JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo "Created isolated browser identity.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed everything the UI stabilisation check made.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/ui-stabilisation-fixture.php create|cleanup [state-path]');
}
