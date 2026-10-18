<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Http\Controller\CourseTagController;
use PHPUnit\Framework\TestCase;

/** A tag cloud: the more courses a tag labels, the bigger it is (owner, 2026-10-18). */
final class TagCloudWeightTest extends TestCase
{
    public function testMoreCoursesMeansABiggerTagEvenWhenOneTagRunsAway(): void
    {
        $tags = CourseTagController::weighted([
            ['name' => 'rare', 'course_count' => 1], ['name' => 'few', 'course_count' => 4],
            ['name' => 'some', 'course_count' => 20], ['name' => 'many', 'course_count' => 60], ['name' => 'most', 'course_count' => 300],
        ]);
        $weights = array_column($tags, 'weight', 'name');
        self::assertSame(1, $weights['rare'], 'the least used tag is the smallest');
        self::assertSame(5, $weights['most'], 'the most used tag is the biggest');
        self::assertTrue($weights['rare'] < $weights['few'] && $weights['few'] < $weights['some'] && $weights['some'] < $weights['many'] && $weights['many'] < $weights['most'], 'each step up in courses is a bigger tag: ' . json_encode($weights));
    }

    public function testTagsWithTheSameNumberOfCoursesAreTheSameMiddleSize(): void
    {
        self::assertSame([3, 3], array_column(CourseTagController::weighted([['course_count' => 7], ['course_count' => 7]]), 'weight'));
        self::assertSame([], CourseTagController::weighted([]));
    }
}
