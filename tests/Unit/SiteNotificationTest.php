<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Configuration\SiteNotification;
use CattoLearning\Course\AssessmentService;
use PHPUnit\Framework\TestCase;

/** The site notification's count, and the words and figures of a result page (owner, 2026-11-05). */
final class SiteNotificationTest extends TestCase
{
    /** @return array<string,int> the units that show */
    private static function shown(int $seconds): array
    {
        $shown = [];
        foreach (SiteNotification::timeLeft($seconds) as $unit) {
            if ($unit['shown']) $shown[$unit['name']] = $unit['count'];
        }
        return $shown;
    }

    public function testTheCountShowsFromTheLargestUnitThatIsNotZeroDownToTheSeconds(): void
    {
        self::assertSame(['week' => 2, 'day' => 2, 'hour' => 3, 'minute' => 5, 'second' => 0], self::shown(16 * 86400 + 3 * 3600 + 300));
        self::assertSame(['day' => 1, 'hour' => 0, 'minute' => 0, 'second' => 1], self::shown(86401));
        self::assertSame(['hour' => 2, 'minute' => 0, 'second' => 0], self::shown(7200));
        self::assertSame(['second' => 59], self::shown(59));
        self::assertSame(['second' => 0], self::shown(-5), 'Never below zero.');
        self::assertCount(5, SiteNotification::timeLeft(59), 'All five are drawn, for the script to show as they are needed.');
    }

    public function testATimeTakenIsInWords(): void
    {
        self::assertSame('1 second', AssessmentService::timeTaken(1));
        self::assertSame('0 seconds', AssessmentService::timeTaken(0));
        self::assertSame('42 minutes 10 seconds', AssessmentService::timeTaken(2530));
        self::assertSame('1 hour', AssessmentService::timeTaken(3600));
    }

    public function testACoursesResultIsHalfItsModuleAssessmentsAndHalfItsFinal(): void
    {
        self::assertSame(['module' => 70.0, 'overall' => 80.0], AssessmentService::courseFigures([60.0, 80.0], 90.0));
        self::assertSame(['module' => 0.0, 'overall' => 45.0], AssessmentService::courseFigures([], 90.0));
    }
}
