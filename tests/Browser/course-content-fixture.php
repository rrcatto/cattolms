<?php

declare(strict_types=1);

// Development-only fixture for course-content-tree.cjs: an ADMIN identity and a course whose
// Course Content is A[a1,a2,a3], B[b1[b1x]], c. `reset` rebuilds that tree between scenarios and
// `empty` removes every row, for the empty-course scenario.
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\DevelopmentFixture;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$boot = CliBootstrap::boot();
$container = $boot['container'];
$db = $container->get(Database::class);
$items = $container->get(CourseItemService::class);
$statePath = $argv[2] ?? '/tmp/catto-course-content-state.json';

$build = static function (array $state) use ($db, $items): array {
    $db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id = :course', ['course' => $state['course']]);
    $nodes = [];
    $place = static function (string $key, ?int $parent) use ($items, $state, &$nodes): void {
        $nodes[$key] = $items->addExisting($state['course'], $state['items'][$key], $parent === null ? [] : ['parent_node_id' => (string) $parent], $state['user']);
    };
    $nodes['A'] = $items->addSection($state['course'], ['title' => 'Section A'], $state['user']);
    foreach (['a1', 'a2', 'a3'] as $key) { $place($key, $nodes['A']); }
    $nodes['B'] = $items->addSection($state['course'], ['title' => 'Section B'], $state['user']);
    $place('b1', $nodes['B']);
    $place('b1x', $nodes['b1']);
    $place('c', null);
    return $nodes;
};

$command = $argv[1] ?? '';
if ($command === 'create') {
    if (is_file($statePath)) throw new RuntimeException('Clean up the previous course content fixture first.');
    $fixture = new DevelopmentFixture($db);
    // A course provider made with its founding administrator (who is also ADMIN), one of its courses and its lessons,
    // every name a real one nobody on the site has (owner, 2026-10-27).
    $company = $fixture->company();
    $user = $company['founder']['id'];
    $email = $company['founder']['email'];
    $container->get(RoleRepository::class)->assign($user, 'ADMIN');
    $made = $fixture->course($user, $company['id']);
    $course = $made['id'];
    $titles = ['a1' => 'Welcome to the course', 'a2' => 'How it works', 'a3' => 'The rules in practice', 'b1' => 'Common mistakes', 'b1x' => 'A worked example', 'c' => 'Course summary'];
    $itemIds = [];
    foreach ($titles as $key => $title) {
        $itemIds[$key] = $items->create(['item_key' => $made['slug'] . '-' . $key, 'item_type' => 'html_lesson', 'title' => $title, 'content_source' => '<p>' . $title . '</p>'], $user);
    }
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Course Content tree browser', $email);
    $state = ['user' => $user, 'email' => $email, 'token' => $token, 'company' => $company['id'], 'course' => $course, 'items' => $itemIds, 'titles' => $titles, 'scrub' => $fixture->seeds()];
    $state['nodes'] = $build($state);
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
} elseif (in_array($command, ['reset', 'empty', 'cleanup'], true)) {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if ($command === 'empty') {
        $db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id = :course', ['course' => $state['course']]);
        $state['nodes'] = [];
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        return;
    }
    if ($command === 'reset') {
        // Items the scenarios created are removed with their placements; the fixture's own stay.
        $db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id = :course', ['course' => $state['course']]);
        $db->executeStatement('DELETE FROM course_items WHERE created_by_user_id = :user AND NOT (id = ANY(CAST(:ids AS BIGINT[])))', ['user' => $state['user'], 'ids' => '{' . implode(',', array_values($state['items'])) . '}']);
        $state['nodes'] = $build($state);
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
        return;
    }
    (new \CattoLearning\Tests\Support\CheckScrub($db))->remove($state['scrub']);
    unlink($statePath);
    echo "Removed the Course Content tree fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/course-content-fixture.php create|reset|cleanup [state-path]');
}
