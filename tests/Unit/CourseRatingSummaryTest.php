<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseRatingSummary;
use PHPUnit\Framework\TestCase;

/**
 * The rating statistics a later popularity calculation consumes. It must carry the count and the
 * distribution as well as the mean, so 5.0 from one review can be told apart from 4.8 from 500.
 */
final class CourseRatingSummaryTest extends TestCase
{
    public function testAnUnratedCourseHasNoAverage(): void
    {
        $summary = CourseRatingSummary::fromDistribution([]);
        self::assertSame(0, $summary->count);
        self::assertNull($summary->average);
        self::assertSame([5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], $summary->distribution);
        self::assertSame('', $summary->toArray()['average_label']);
        self::assertSame(0, $summary->toArray()['filled_stars']);
    }

    public function testTheMeanCountAndDistributionComeFromTheStarCounts(): void
    {
        $summary = CourseRatingSummary::fromDistribution([5 => 300, 4 => 200]);
        self::assertSame(500, $summary->count);
        self::assertSame(4.6, $summary->average);
        $display = $summary->toArray();
        self::assertSame('4.6', $display['average_label']);
        self::assertSame(5, $display['filled_stars']);
        self::assertSame([60, 40, 0, 0, 0], array_column($display['distribution'], 'percent'));
    }

    public function testOneReviewIsDistinguishableFromManyWithoutAnyWeighting(): void
    {
        $single = CourseRatingSummary::fromDistribution([5 => 1]);
        $many = CourseRatingSummary::fromDistribution([5 => 400, 4 => 100]);
        self::assertSame(5.0, $single->average);
        self::assertSame(1, $single->count);
        self::assertSame(4.8, $many->average);
        self::assertSame(500, $many->count, 'The count travels with the mean, for a confidence-aware score later.');
    }

    public function testStarsOutsideOneToFiveAreIgnored(): void
    {
        self::assertSame(2, CourseRatingSummary::fromDistribution([0 => 4, 3 => 2, 6 => 9])->count);
    }
}
