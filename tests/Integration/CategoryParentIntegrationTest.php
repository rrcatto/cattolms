<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The Parent category picker and the rules behind it: only valid parents are offered, and the
 * service refuses the same cycles and fourth levels however the request arrives.
 *
 *   A > A1 > A1x      B > B1      C (no children)      D > D1
 */
final class CategoryParentIntegrationTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private CourseService $service;
    private string $suffix = '';
    /** @var array<string,int> */
    private array $ids = [];
    private int $userId = 0;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->service = IntegrationContainer::get()->get(CourseService::class);
        $this->suffix = $this->fixture->suffix();
        $this->userId = $this->fixture->createUser('Parent test ' . $this->suffix, 'parent-test-' . $this->suffix . '@seed.test');
        foreach ([['A', null], ['A1', 'A'], ['A1x', 'A1'], ['B', null], ['B1', 'B'], ['C', null], ['D', null], ['D1', 'D']] as [$key, $parent]) {
            $this->ids[$key] = $this->fixture->createCategory($this->categoryName($key), 'parent-test-' . strtolower($key) . '-' . $this->suffix, $parent === null ? 0 : $this->ids[$parent]);
        }
    }

    protected function tearDown(): void
    {
        // Moves change the shape of the tree, so delete deepest first rather than in creation order.
        foreach ([3, 2, 1] as $level) {
            $this->db->executeStatement('DELETE FROM course_categories WHERE slug LIKE :slug AND level = :level', ['slug' => 'parent-test-%-' . $this->suffix, 'level' => $level]);
        }
        $this->fixture->cleanup();
    }

    public function testNewCategoryOffersNoParentThenLevelOneAndTwoOnly(): void
    {
        $picker = $this->service->categoryParentPicker();
        self::assertSame(['value' => '0', 'label' => 'No parent (top-level category)', 'depth' => 0, 'path' => 'No parent'], $picker['items'][0]);
        self::assertSame('0', $picker['selected']);
        $offered = $this->offered($picker);
        foreach (['A', 'A1', 'B', 'B1', 'C', 'D', 'D1'] as $key) self::assertArrayHasKey($key, $offered, $key . ' is a valid parent for a new category.');
        self::assertArrayNotHasKey('A1x', $offered, 'A level-3 category would create a fourth level.');
        self::assertSame(1, $offered['A']['depth']);
        self::assertSame(2, $offered['A1']['depth']);
        self::assertSame($this->categoryName('A') . ' › ' . $this->categoryName('A1'), $offered['A1']['path']);
        $values = array_column($picker['items'], 'value');
        self::assertLessThan(array_search((string) $this->ids['A1'], $values, true), array_search((string) $this->ids['A'], $values, true), 'A level-2 category is listed beneath its parent.');
        self::assertNotContains('0', array_slice($values, 1), 'No parent is offered once, first.');
    }

    public function testEditingExcludesItselfItsDescendantsAndParentsThatWouldOverflowTheDepth(): void
    {
        $forA = $this->offered($this->service->categoryParentPicker($this->ids['A']));
        self::assertSame([], $forA, 'A has grandchildren, so only No parent keeps them within three levels.');

        $forA1 = $this->offered($this->service->categoryParentPicker($this->ids['A1']));
        self::assertArrayNotHasKey('A1', $forA1, 'A category is never its own parent.');
        self::assertArrayNotHasKey('A1x', $forA1, 'A category is never filed under its own descendant.');
        self::assertArrayHasKey('B', $forA1);
        self::assertArrayNotHasKey('B1', $forA1, 'A1 has children; under a level-2 parent they would reach level 4.');

        $forD = $this->offered($this->service->categoryParentPicker($this->ids['D']));
        self::assertArrayHasKey('B', $forD);
        self::assertArrayNotHasKey('B1', $forD);

        $forC = $this->offered($this->service->categoryParentPicker($this->ids['C']));
        self::assertArrayHasKey('B1', $forC, 'A category with no children may sit under a level-2 category and become level 3.');
        self::assertArrayNotHasKey('C', $forC);
    }

    public function testThePickerSelectsTheCurrentParentAndItsPath(): void
    {
        $picker = $this->service->categoryParentPicker($this->ids['A1x']);
        self::assertSame((string) $this->ids['A1'], $picker['selected']);
        $chosen = array_values(array_filter($picker['items'], static fn(array $item): bool => $item['value'] === $picker['selected']));
        self::assertSame($this->categoryName('A') . ' › ' . $this->categoryName('A1'), $chosen[0]['path'] ?? null);
        self::assertSame('0', $this->service->categoryParentPicker($this->ids['B'])['selected']);
    }

    public function testTheServiceRefusesCyclesAndFourthLevelsAndRelevelsMovedBranches(): void
    {
        $this->assertRefused('A1', 'A1', 'cannot be filed under itself');
        $this->assertRefused('A', 'A1', 'own sub-categories');
        $this->assertRefused('A', 'A1x', 'three levels deep');
        $this->assertRefused('A1', 'A1x', 'three levels deep');
        $this->assertRefused('D', 'B1', 'below the third level');
        $this->assertRefused('A', 'B', 'below the third level');

        $this->move('C', 'B1');
        self::assertSame(3, $this->level('C'));

        $this->move('D', 'B');
        self::assertSame([2, 3], [$this->level('D'), $this->level('D1')], 'Descendants follow their parent.');
        $this->move('D', '0');
        self::assertSame([1, 2], [$this->level('D'), $this->level('D1')], 'Moving to No parent remains supported.');

        $this->move('A1', 'B');
        self::assertSame([2, 3], [$this->level('A1'), $this->level('A1x')]);
    }

    /**
     * @param array{items:list<array<string,mixed>>,selected:string} $picker
     * @return array<string,array<string,mixed>>
     */
    private function offered(array $picker): array
    {
        $byId = array_flip(array_map('strval', $this->ids));
        $offered = [];
        foreach ($picker['items'] as $item) {
            if (isset($byId[$item['value']])) $offered[$byId[$item['value']]] = $item;
        }
        return $offered;
    }

    private function assertRefused(string $category, string $parent, string $message): void
    {
        try {
            $this->move($category, $parent);
            self::fail($category . ' was filed under ' . $parent);
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function move(string $category, string $parent): void
    {
        $this->service->updateCategory($this->ids[$category], [
            'name' => $this->categoryName($category),
            'slug' => 'parent-test-' . strtolower($category) . '-' . $this->suffix,
            'parent_id' => $parent === '0' ? 0 : $this->ids[$parent],
        ], $this->userId);
    }

    private function level(string $category): int
    {
        return (int) $this->db->fetchOne('SELECT level FROM course_categories WHERE id = :id', ['id' => $this->ids[$category]]);
    }

    private function categoryName(string $key): string
    {
        return 'Parent test ' . $key . ' ' . $this->suffix;
    }
}
