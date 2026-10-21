<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Auth\AdultAge;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Adults only (owner, 2026-10-20: "For now, for the launch, let's accept only adult learners"): a date of
 * birth is a real date, not in the future, and 18 or older on the day; a profile may leave it empty.
 */
final class AdultAgeTest extends TestCase
{
    public function testEighteenOnTheDayIsAnAdultAndADayLessIsNot(): void
    {
        $today = new DateTimeImmutable('2026-10-20 15:30');
        self::assertSame('2008-10-20', AdultAge::fromInput('2008-10-20', $today), 'Eighteen today.');
        self::assertSame('1990-03-14', AdultAge::fromInput(' 1990-03-14 ', $today));
        foreach (['2008-10-21' => AdultAge::REFUSED, '2027-01-01' => 'A date of birth cannot be in the future.', '1900-01-01' => 'Check the year of your date of birth.',
            '2001-02-29' => 'Enter your date of birth as a real date, for example 1990-03-14.', '' => 'Enter your date of birth.'] as $born => $message) {
            try {
                AdultAge::fromInput((string) $born, $today);
                self::fail('Refused: ' . $born);
            } catch (InvalidArgumentException $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }
    }

    public function testAProfileMayLeaveItEmptyButNeverMakesSomeoneUnder18(): void
    {
        $today = new DateTimeImmutable('2026-10-20');
        self::assertSame('', AdultAge::optionalInput('', $today), 'Empty: asked again at the next sign-in.');
        self::assertSame('1990-03-14', AdultAge::optionalInput('1990-03-14', $today));
        $this->expectExceptionMessage(AdultAge::REFUSED);
        AdultAge::optionalInput('2010-03-14', $today);
    }
}
