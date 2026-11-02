<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseNavigation;
use PHPUnit\Framework\TestCase;

/** Previous and Next follow one reading order: every item, and every section that has a page. */
final class CourseNavigationTest extends TestCase
{
    public function testTheSequenceHoldsItemsAndSectionsWithAPage(): void
    {
        self::assertSame([1, 2, 3, 5, 6, 7], array_column(CourseNavigation::sequence($this->structure()), 'id'));
    }

    public function testNeighboursStepThroughSectionsAndStopAtTheEnds(): void
    {
        self::assertSame([null, 2], $this->around(1), 'A section with an introduction is the first stop.');
        self::assertSame([3, 6], $this->around(5), 'An assessment is followed by the next section with a page, past a heading-only one.');
        self::assertSame([5, 7], $this->around(6), 'A section with an outline is a stop.');
        self::assertSame([6, null], $this->around(7));
        self::assertSame([null, null], $this->around(4), 'A heading-only section is not a stop.');
        self::assertSame([null, null], $this->around(99));
    }

    /** @return array{0:int|null,1:int|null} */
    private function around(int $id): array
    {
        $around = CourseNavigation::neighbours($this->structure(), $id);
        return [$around['previous']['id'] ?? null, $around['next']['id'] ?? null];
    }

    /** @return list<array<string,mixed>> */
    private function structure(): array
    {
        return [
            ['id' => 1, 'node_type' => 'section', 'section_introduction_html' => '<p>Welcome</p>', 'show_outline' => false],
            ['id' => 2, 'node_type' => 'item', 'item_type' => 'html_lesson'],
            ['id' => 3, 'node_type' => 'item', 'item_type' => 'html_lesson'],
            ['id' => 4, 'node_type' => 'section', 'section_introduction_html' => '  ', 'show_outline' => false],
            ['id' => 5, 'node_type' => 'item', 'item_type' => 'assessment'],
            ['id' => 6, 'node_type' => 'section', 'section_introduction_html' => '', 'show_outline' => true],
            ['id' => 7, 'node_type' => 'item', 'item_type' => 'html_lesson'],
        ];
    }

    /** The sidebar opens only the way to the current stop (owner, 2026-11-02), each section holding its rows. */
    public function testATickChangesThePartsRowAndThoseOfTheSectionsHoldingIt(): void
    {
        $row = static fn(int $id, ?int $parent): array => ['id' => $id, 'parent_node_id' => $parent];
        $structure = [$row(1, null), $row(2, null), $row(3, 2), $row(4, 2), $row(5, 4), $row(6, 4)];
        self::assertSame([5, 4, 2], array_column(CourseNavigation::rowsUpTo($structure, 5), 'id'), 'The part, then each section up from it.');
        self::assertSame([1], array_column(CourseNavigation::rowsUpTo($structure, 1), 'id'), 'A part outside any section is its own row.');
        self::assertSame([], CourseNavigation::rowsUpTo($structure, 99), 'A row the outline does not hold changes nothing.');
    }

    public function testTheOutlineOpensOnlyTheSectionYouAreIn(): void
    {
        $row = static fn(int $id, ?int $parent, string $type): array => ['id' => $id, 'parent_node_id' => $parent, 'node_type' => $type];
        $structure = [$row(1, null, 'item'), $row(2, null, 'section'), $row(3, 2, 'item'), $row(4, 2, 'section'), $row(5, 4, 'item'), $row(6, null, 'section'), $row(7, 6, 'item'), $row(8, null, 'section')];
        $open = static function (array $rows) use (&$open): array {
            $ids = [];
            foreach ($rows as $node) { if ($node['is_open']) { $ids[] = $node['id']; } $ids = [...$ids, ...$open($node['children'])]; }
            return $ids;
        };

        $outline = CourseNavigation::outline($structure, 7);
        self::assertSame([1, 2, 6, 8], array_column($outline, 'id'), 'Each section holds its rows, in order.');
        self::assertSame([3, 4], array_column($outline[1]['children'], 'id'));
        self::assertSame([5], array_column($outline[1]['children'][1]['children'], 'id'), 'However deep.');
        self::assertSame([6], $open($outline), 'A part opens its section, and only that.');
        self::assertSame([2, 4], $open(CourseNavigation::outline($structure, 5)), 'A part deep inside opens the whole way to it.');
        self::assertSame([6], $open(CourseNavigation::outline($structure, 6)), 'A section\'s own page opens it.');
        self::assertSame([], $open(CourseNavigation::outline($structure, 8)), 'A section with nothing in it has nothing to open.');
        self::assertSame([], $open(CourseNavigation::outline($structure, 1)), 'A part in no section opens none.');
        self::assertSame([], $open(CourseNavigation::outline($structure, null)), 'The course introduction opens none.');
        self::assertSame([], $open(CourseNavigation::outline($structure, 99)), 'A stop not in the outline opens none.');

        // The public preview shows a part whose section is not public: it stands at the top level.
        self::assertSame([3], array_column(CourseNavigation::outline([$row(3, 2, 'item')], 3), 'id'));
    }
}
