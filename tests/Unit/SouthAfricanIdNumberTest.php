<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Auth\AdultAge;
use CattoLearning\Auth\SouthAfricanIdNumber;
use CattoLearning\Support\Env;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Only people with a valid South African ID number register (owner, 2026-10-27; 1A citizens and permanent residents,
 * 2A the date of birth taken from it): 13 digits, a real date of birth, the citizenship digit 0 or 1 and the Luhn
 * checksum; 18 or older (adults only, 2026-10-20).
 */
final class SouthAfricanIdNumberTest extends TestCase
{
    private const TODAY = '2026-10-27 15:30';

    public function testEighteenOnTheDayIsAnAdultAndADayLessIsNot(): void
    {
        $today = new DateTimeImmutable(self::TODAY);
        self::assertSame('2008-10-27', SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('2008-10-27'), 1234), $today)->birthdate);
        $this->expectExceptionMessage(AdultAge::REFUSED);
        SouthAfricanIdNumber::fromInput(SouthAfricanIdNumber::make(new DateTimeImmutable('2008-10-28'), 1234), $today);
    }

    public function testTheFingerprintIsKeyedWithTheSiteKeyAsStored(): void
    {
        $number = SouthAfricanIdNumber::fromInput(' 800101 5009 087 ', new DateTimeImmutable(self::TODAY));
        self::assertSame(['8001015009087', '1980-01-01', true, '800101 **** ***'], [$number->digits, $number->birthdate, $number->isCitizen(), SouthAfricanIdNumber::masked($number->digits)]);
        self::assertSame(hash_hmac('sha256', 'sa-id:8001015009087', Env::string('APP_KEY')), $number->fingerprint(), 'The fingerprints kept in users.id_number_hash still find their numbers.');
        $this->expectExceptionMessage(SouthAfricanIdNumber::INVALID);
        SouthAfricanIdNumber::fromInput('8001015009088', new DateTimeImmutable(self::TODAY));
    }
}
