<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseService;
use PHPUnit\Framework\TestCase;

/** The category management counts: levels by parent_id, and courses filed directly in a category. */
final class CategoryStatisticsTest extends TestCase
{
    public function testAnEmptyTaxonomyCountsNothing(): void
    {
        self::assertSame(['main' => 0, 'sub' => 0, 'sub_sub' => 0, 'total' => 0, 'with_courses' => 0, 'empty' => 0, 'invalid' => []], CourseService::categoryStatistics([]));
    }

    public function testAMixedThreeLevelHierarchyIsCountedByParent(): void
    {
        $stats = CourseService::categoryStatistics([
            self::category(1, null, 0), self::category(2, null, 0),
            self::category(3, 1, 2), self::category(4, 1, 0), self::category(5, 2, 0),
            self::category(6, 3, 1), self::category(7, 3, 0), self::category(8, 5, 4),
            // A stale stored level must not matter: this is a sub-category by its parent.
            self::category(9, 2, 0, 3),
        ]);
        self::assertSame(2, $stats['main']);
        self::assertSame(4, $stats['sub']);
        self::assertSame(3, $stats['sub_sub']);
        self::assertSame(9, $stats['total']);
        self::assertSame($stats['main'] + $stats['sub'] + $stats['sub_sub'], $stats['total'], 'A valid hierarchy totals exactly its three levels.');
        self::assertSame([], $stats['invalid']);
    }

    public function testCoursesCountOnlyWhereTheyAreFiledDirectly(): void
    {
        $stats = CourseService::categoryStatistics([self::category(1, null, 0), self::category(2, 1, 0), self::category(3, 2, 5)]);
        self::assertSame(1, $stats['with_courses'], 'Only the sub-sub-category has courses filed in it.');
        self::assertSame(2, $stats['empty'], 'Its parents are empty although their branch holds courses.');
        self::assertSame($stats['total'], $stats['with_courses'] + $stats['empty']);
    }

    public function testCategoriesOutsideTheHierarchyAreReportedNotCountedAsLevelThree(): void
    {
        $stats = CourseService::categoryStatistics([
            self::category(1, null, 0), self::category(2, 1, 0), self::category(3, 2, 0),
            self::category(4, 3, 0, 3, 'Too deep'),
            self::category(5, 6, 0, 2, 'Loop A'), self::category(6, 5, 0, 2, 'Loop B'),
            self::category(7, 99, 0, 2, 'Orphan'),
        ]);
        self::assertSame([1, 1, 1], [$stats['main'], $stats['sub'], $stats['sub_sub']], 'Nothing invalid is counted at a level.');
        self::assertSame(7, $stats['total']);
        self::assertSame(['Too deep: it is deeper than level 3', 'Loop A: it is in a parent loop', 'Loop B: it is in a parent loop', 'Orphan: its parent is missing'], $stats['invalid']);
    }

    /** @return array<string,mixed> */
    private static function category(int $id, ?int $parent, int $courses, int $level = 0, string $name = ''): array
    {
        return ['id' => $id, 'parent_id' => $parent, 'level' => $level, 'name' => $name ?: 'Category ' . $id, 'course_count' => $courses];
    }
}
