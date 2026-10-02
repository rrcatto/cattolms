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
    $suffix = $fixture->suffix();
    $email = 'course-content-tree-' . $suffix . '@example.test';
    $user = $fixture->createUser('Course Content tree browser check', $email);
    $container->get(RoleRepository::class)->assign($user, 'ADMIN');
    $company = $fixture->createCompany($user, 'Course Content tree ' . $suffix, 'course-content-tree-' . $suffix . '.example.test');
    $course = $fixture->createCourse($user, $company, 'course-content-tree-' . $suffix, 'Course Content tree ' . $suffix);
    $itemIds = [];
    foreach (['a1', 'a2', 'a3', 'b1', 'b1x', 'c'] as $key) {
        $itemIds[$key] = $items->create(['item_key' => 'cct-' . $key . '-' . $suffix, 'item_type' => 'html_lesson', 'title' => 'Item ' . $key, 'content_source' => '<p>' . $key . '</p>'], $user);
    }
    $token = Token::generate();
    $container->get(AuthSessionRepository::class)->create($user, Token::hash($token), 7200, '', 'Course Content tree browser', $email);
    $state = ['user' => $user, 'email' => $email, 'token' => $token, 'company' => $company, 'course' => $course, 'items' => $itemIds];
    $state['nodes'] = $build($state);
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    chmod($statePath, 0600);
    echo json_encode($state, JSON_THROW_ON_ERROR), "\n";
} elseif (in_array($command, ['reset', 'empty', 'cleanup'], true)) {
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    if (!str_starts_with($state['email'], 'course-content-tree-')) throw new RuntimeException('Unexpected fixture identity.');
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
    $db->executeStatement('DELETE FROM courses WHERE id = :id', ['id' => $state['course']]);
    $db->executeStatement('DELETE FROM course_items WHERE created_by_user_id = :id', ['id' => $state['user']]);
    $db->executeStatement('DELETE FROM companies WHERE id = :id', ['id' => $state['company']]);
    $db->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $state['user']]);
    $db->executeStatement('DELETE FROM users WHERE id = :id AND EXISTS (SELECT 1 FROM user_emails WHERE user_id = users.id AND email = :email)', ['id' => $state['user'], 'email' => $state['email']]);
    unlink($statePath);
    echo "Removed the Course Content tree fixture.\n";
} else {
    throw new RuntimeException('Usage: php tests/Browser/course-content-fixture.php create|reset|cleanup [state-path]');
}
