<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Course Content moves are saved at once by one validated operation. Every assertion reads the
 * saved tree back from the database.
 *
 * Starting tree:
 *   A (section)
 *     a1, a2, a3
 *   B (section)
 *     b1
 *       b1x
 *   c (item)
 */
final class CourseContentArrangementIntegrationTest extends TestCase
{
    private Database $db;
    private CourseItemService $items;
    private CourseItemRepository $records;
    private string $suffix;
    private int $owner;
    private int $course;
    private int $other;
    /** @var array<string,int> */
    private array $n = [];

    protected function setUp(): void
    {
        $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->items = $container->get(CourseItemService::class);
        $this->records = $container->get(CourseItemRepository::class);
        $this->db->beginTransaction();
        $fixture = new DevelopmentFixture($this->db);
        $this->suffix = $fixture->suffix();
        $this->owner = $fixture->createUser('Arrange ' . $this->suffix);
        $company = $fixture->createCompany($this->owner, 'Arrange ' . $this->suffix, $this->suffix . '.example.test');
        $this->course = $fixture->createCourse($this->owner, $company, 'arrange-' . $this->suffix, 'Arrange ' . $this->suffix);
        $this->other = $fixture->createCourse($this->owner, $company, 'arrange-other-' . $this->suffix, 'Arrange other ' . $this->suffix);
        $this->n['A'] = $this->items->addSection($this->course, ['title' => 'A'], $this->owner);
        foreach (['a1', 'a2', 'a3'] as $key) { $this->n[$key] = $this->place($key, $this->n['A']); }
        $this->n['B'] = $this->items->addSection($this->course, ['title' => 'B'], $this->owner);
        $this->n['b1'] = $this->place('b1', $this->n['B']);
        $this->n['b1x'] = $this->place('b1x', $this->n['b1']);
        $this->n['c'] = $this->place('c', null);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    public function testTheStartingTree(): void
    {
        self::assertSame('A[a1,a2,a3],B[b1[b1x]],c', $this->tree());
    }

    public function testReorderWithinALevel(): void
    {
        $this->items->arrange($this->course, [$this->n['a3']], $this->n['A'], 1, $this->owner);
        self::assertSame('A[a1,a3,a2],B[b1[b1x]],c', $this->tree());
        $this->items->move($this->course, [$this->n['a1']], 'down', $this->owner);
        self::assertSame('A[a3,a1,a2],B[b1[b1x]],c', $this->tree());
        $this->items->move($this->course, [$this->n['a2']], 'up', $this->owner);
        self::assertSame('A[a3,a2,a1],B[b1[b1x]],c', $this->tree());
    }

    public function testTopAndBottom(): void
    {
        $this->items->move($this->course, [$this->n['a3']], 'top', $this->owner);
        self::assertSame('A[a3,a1,a2],B[b1[b1x]],c', $this->tree());
        $this->items->move($this->course, [$this->n['a3']], 'bottom', $this->owner);
        self::assertSame('A[a1,a2,a3],B[b1[b1x]],c', $this->tree());
        $this->items->move($this->course, [$this->n['c']], 'top', $this->owner);
        self::assertSame('c,A[a1,a2,a3],B[b1[b1x]]', $this->tree());
    }

    public function testIntoOutOfAndBetweenSections(): void
    {
        $this->items->arrange($this->course, [$this->n['c']], $this->n['A'], 0, $this->owner);
        self::assertSame('A[c,a1,a2,a3],B[b1[b1x]]', $this->tree(), 'Into a section at a position.');
        $this->items->arrange($this->course, [$this->n['a2']], $this->n['B'], null, $this->owner);
        self::assertSame('A[c,a1,a3],B[b1[b1x],a2]', $this->tree(), 'Between sections, appended.');
        $this->items->move($this->course, [$this->n['a1']], 'out', $this->owner);
        self::assertSame('A[c,a3],a1,B[b1[b1x],a2]', $this->tree(), 'Out of a section, beside it.');
        $this->items->arrange($this->course, [$this->n['a3']], null, 3, $this->owner);
        self::assertSame('A[c],a1,B[b1[b1x],a2],a3', $this->tree(), 'Out to the outer level at a position.');
    }

    public function testSectionReorderMovesTheWholeSubtree(): void
    {
        $this->items->arrange($this->course, [$this->n['B']], null, 0, $this->owner);
        self::assertSame('B[b1[b1x]],A[a1,a2,a3],c', $this->tree());
        $this->items->arrange($this->course, [$this->n['b1']], $this->n['A'], 1, $this->owner);
        self::assertSame('B,A[a1,b1[b1x],a2,a3],c', $this->tree(), 'A subtree moves with its children.');
    }

    public function testNestedSectionMove(): void
    {
        $this->items->arrange($this->course, [$this->n['A']], $this->n['B'], 0, $this->owner);
        self::assertSame('B[A[a1,a2,a3],b1[b1x]],c', $this->tree());
        self::assertSame(3, $this->records->nodeDepth($this->course, $this->n['a1']));
    }

    public function testDepthBeyondThreeLevelsIsRejected(): void
    {
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['B']], $this->n['A'], null, $this->owner), 'at most 3 levels');
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['a1']], $this->n['b1x'], null, $this->owner), 'at most 3 levels');
        $this->refused(fn() => $this->items->addSection($this->course, ['title' => 'Too deep', 'parent_node_id' => (string) $this->n['b1x']], $this->owner), 'at most 3 levels');
        self::assertSame('A[a1,a2,a3],B[b1[b1x]],c', $this->tree());
    }

    public function testCyclesAreRejected(): void
    {
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['B']], $this->n['b1'], null, $this->owner), 'inside itself');
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['B']], $this->n['B'], null, $this->owner), 'inside itself');
        self::assertSame('A[a1,a2,a3],B[b1[b1x]],c', $this->tree());
    }

    public function testInvalidPositionsAndForeignRowsAreRejected(): void
    {
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['a1']], $this->n['A'], 3, $this->owner), 'valid position');
        $this->refused(fn() => $this->items->move($this->course, [$this->n['a1']], 'sideways', $this->owner), 'valid Course Content move');
        $this->refused(fn() => $this->items->move($this->course, [$this->n['c']], 'out', $this->owner), 'outer level');
        $foreign = $this->items->addSection($this->other, ['title' => 'Elsewhere'], $this->owner);
        $this->refused(fn() => $this->items->arrange($this->course, [$foreign], null, null, $this->owner), 'no longer in this course');
        $this->refused(fn() => $this->items->arrange($this->course, [$this->n['a1']], $foreign, null, $this->owner), 'no longer in this course');
        $this->refused(fn() => $this->items->addSection($this->course, ['title' => 'Stray', 'parent_node_id' => (string) $foreign], $this->owner), 'not part of this course');
        self::assertSame('A[a1,a2,a3],B[b1[b1x]],c', $this->tree());
    }

    public function testSelectionMovesTogetherInOrder(): void
    {
        $this->items->move($this->course, [$this->n['a2'], $this->n['a3']], 'top', $this->owner);
        self::assertSame('A[a2,a3,a1],B[b1[b1x]],c', $this->tree());
        $this->items->move($this->course, [$this->n['a2'], $this->n['a3']], 'up', $this->owner);
        self::assertSame('A[a2,a3,a1],B[b1[b1x]],c', $this->tree(), 'A row at the edge blocks the selected row behind it.');
        $this->items->arrange($this->course, [$this->n['a2'], $this->n['B'], $this->n['b1x']], null, 0, $this->owner);
        self::assertSame('a2,B[b1[b1x]],A[a3,a1],c', $this->tree(), 'A selected row inside another selected row travels with it.');
    }

    public function testInlineInsertionLandsAtTheExactPlace(): void
    {
        $section = $this->items->addSection($this->course, ['title' => 'N', 'parent_node_id' => (string) $this->n['A'], 'insert_index' => '1'], $this->owner);
        $this->n['N'] = $section;
        self::assertSame('A[a1,N,a2,a3],B[b1[b1x]],c', $this->tree());
        $this->n['e'] = $this->items->addExisting($this->course, $this->item('e'), ['insert_index' => '0'], $this->owner);
        self::assertSame('e,A[a1,N,a2,a3],B[b1[b1x]],c', $this->tree());
        $created = $this->items->createAttached($this->course, ['item_key' => 'new-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'New', 'content_source' => '<p>New</p>', 'parent_node_id' => (string) $this->n['B'], 'insert_index' => '0'], $this->owner);
        $this->n['new'] = (int) $this->db->fetchOne('SELECT node_id FROM course_item_placements WHERE course_item_id=:item', ['item' => $created]);
        self::assertSame('e,A[a1,N,a2,a3],B[new,b1[b1x]],c', $this->tree());
        $this->refused(fn() => $this->items->addSection($this->course, ['title' => 'Far', 'parent_node_id' => (string) $this->n['B'], 'insert_index' => '9'], $this->owner), 'valid position');
    }

    public function testPositionsArePersistedContiguouslyAndSettingsSurviveMoves(): void
    {
        $this->items->updatePlacement($this->course, $this->n['a2'], ['delay_days' => '2', 'display_title_override' => 'Shown'], $this->owner);
        $this->items->arrange($this->course, [$this->n['a2']], $this->n['B'], 0, $this->owner);
        $placement = $this->records->placement($this->course, $this->n['a2']);
        self::assertSame(2880, (int) $placement['relative_delay_minutes'], 'Availability rules move with the row.');
        self::assertSame('Shown', $placement['display_title_override']);
        $positions = $this->db->fetchAllAssociative('SELECT COALESCE(parent_node_id,0) AS parent, array_agg(position ORDER BY position) AS positions FROM course_structure_nodes WHERE course_id=:course GROUP BY 1', ['course' => $this->course]);
        foreach ($positions as $group) {
            $list = array_map('intval', explode(',', trim((string) $group['positions'], '{}')));
            self::assertSame(range(1, count($list)), $list, 'Each parent numbers its rows 1..n.');
        }
    }

    public function testANoOpMoveWritesNothing(): void
    {
        $before = $this->db->fetchAllAssociative('SELECT id,updated_at FROM course_structure_nodes WHERE course_id=:course ORDER BY id', ['course' => $this->course]);
        $this->items->move($this->course, [$this->n['a1']], 'up', $this->owner);
        $this->items->arrange($this->course, [$this->n['a1']], $this->n['A'], 0, $this->owner);
        self::assertSame($before, $this->db->fetchAllAssociative('SELECT id,updated_at FROM course_structure_nodes WHERE course_id=:course ORDER BY id', ['course' => $this->course]));
    }

    public function testMoveDestinationsExcludeTheRowsOwnSubtreeAndTooDeepTargets(): void
    {
        $destinations = $this->items->moveDestinations($this->course, [$this->n['b1']]);
        self::assertContains(null, $destinations);
        self::assertContains($this->n['A'], $destinations);
        self::assertNotContains($this->n['b1'], $destinations, 'Not itself.');
        self::assertNotContains($this->n['b1x'], $destinations, 'Not inside itself.');
        self::assertNotContains($this->n['a1'], $destinations, 'b1 has a child, so a level-2 target would make level 4.');
    }

    private function place(string $key, ?int $parent): int
    {
        return $this->items->addExisting($this->course, $this->item($key), $parent === null ? [] : ['parent_node_id' => (string) $parent], $this->owner);
    }

    private function item(string $key): int
    {
        return $this->items->create(['item_key' => $key . '-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => $key, 'content_source' => '<p>' . $key . '</p>'], $this->owner);
    }

    /** The saved tree as text, e.g. "A[a1,a2],c". */
    private function tree(): string
    {
        $names = array_flip($this->n); $children = [];
        foreach ($this->records->structure($this->course) as $row) { $children[(int) ($row['parent_node_id'] ?? 0)][] = (int) $row['id']; }
        $render = function (int $parent) use (&$render, $children, $names): string {
            return implode(',', array_map(static fn(int $id): string => ($names[$id] ?? (string) $id) . (isset($children[$id]) ? '[' . $render($id) . ']' : ''), $children[$parent] ?? []));
        };
        return $render(0);
    }

    private function refused(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('The operation was accepted: expected "' . $message . '".');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
