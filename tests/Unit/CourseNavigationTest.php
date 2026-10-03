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
}
