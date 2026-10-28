<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Auth\AdultAge;
use CattoLearning\Auth\SouthAfricanIdNumber;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Only people with a valid South African ID number register (owner, 2026-10-27; 1A citizens and permanent residents,
 * 2A the date of birth taken from it): 13 digits, a real date of birth, the citizenship digit 0 or 1 and the Luhn
 * checksum; 18 or older (adults only, 2026-10-20).
 */
final class SouthAfricanIdNumberTest extends TestCase
{
    private const TODAY = '2026-10-27 15:30';

    public function testAValidNumberGivesItsDateOfBirth(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $number = SouthAfricanIdNumber::fromInput('8001015009087', $today);
        self::assertSame('1980-01-01', $number->birthdate);
        self::assertTrue($number->isCitizen());
        self::assertSame('8001015009087', SouthAfricanIdNumber::fromInput(' 800101 5009 087 ', $today)->digits, 'Spaces as people type them are fine.');
        self::assertSame('800101 **** ***', SouthAfricanIdNumber::masked($number->digits));
    }

    public function testPermanentResidentsHoldValidNumbersToo(): void
    {
        $made = SouthAfricanIdNumber::make(new DateTimeImmutable('1985-07-04'), 5123, false);
        self::assertSame('1', $made[10]);
        self::assertFalse(SouthAfricanIdNumber::fromInput($made, new DateTimeImmutable(self::TODAY))->isCitizen());
    }

    public function testAnyMistakeIsRefusedInPlainWords(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $cases = [
            '' => SouthAfricanIdNumber::ENTER,
            '80010150090' => SouthAfricanIdNumber::ENTER,
            '80010150090871' => SouthAfricanIdNumber::ENTER,
            '80010A5009087' => SouthAfricanIdNumber::ENTER,
            '8001015009088' => SouthAfricanIdNumber::INVALID,
            '8013015009087' => SouthAfricanIdNumber::INVALID,
            '8002305009087' => SouthAfricanIdNumber::INVALID,
        ];
        foreach ($cases as $typed => $words) {
            try {
                SouthAfricanIdNumber::fromInput($typed, $today);
                self::fail('Accepted: ' . $typed);
            } catch (InvalidArgumentException $refused) {
                self::assertSame($words, $refused->getMessage(), (string) $typed);
            }
        }
        // A citizenship digit other than 0 or 1, even with a checksum that fits.
        $first12 = '800101500928';
        for ($check = 0; $check <= 9; $check++) {
            try {
                SouthAfricanIdNumber::fromInput($first12 . $check, $today);
                self::fail('Accepted a citizenship digit of 2.');
            } catch (InvalidArgumentException $refused) {
                self::assertSame(SouthAfricanIdNumber::INVALID, $refused->getMessage());
            }
        }
    }

    public function testEighteenOnTheDayIsAnAdultAndADayLessIsNot(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        self::assertSame('2008-10-27', SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('2008-10-27'), 1234), $today)->birthdate);
        $this->expectExceptionMessage(AdultAge::REFUSED);
        SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('2008-10-28'), 1234), $today);
    }

    public function testTheCenturyIsTheOneThatMakesSense(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        self::assertSame('1999-12-31', SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('1999-12-31'), 9), $today)->birthdate);
        self::assertSame('2001-02-28', SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('2001-02-28'), 9), $today)->birthdate);
        self::assertSame('1927-06-01', SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('1927-06-01'), 9), $today)->birthdate, 'Not 2027, which is to come.');
    }

    public function testEachMadeNumberIsValidAndTheFingerprintFindsTheSameNumber(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $made = SouthAfricanIdNumber::make((new DateTimeImmutable('1960-01-01'))->modify('+' . ($i * 60) . ' days'), $i * 41);
            $number = SouthAfricanIdNumber::fromInput($made, $today);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $number->fingerprint());
            $seen[$number->fingerprint()] = true;
        }
        self::assertCount(200, $seen);
        self::assertSame(SouthAfricanIdNumber::fromInput('8001015009087', $today)->fingerprint(), SouthAfricanIdNumber::fromInput('800101 5009 087', $today)->fingerprint());
    }
}
