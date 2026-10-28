<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Commerce\Application\BankPayments;
use CattoLearning\Support\AccountReference;
use PHPUnit\Framework\TestCase;

/**
 * A payer's reference written with spaces is read whole (2026-10-27): read three words at a time, "CL 20261027 30492 Z"
 * could make another valid-looking reference before reaching the whole one, so a payment was not matched about one time
 * in 37.
 */
final class BankReferenceReadingTest extends TestCase
{
    public function testAReferenceWrittenWithSpacesIsReadWholeEvenWhenItsFirstWordsMakeAnotherValidOne(): void
    {
        $found = null;
        for ($number = 10000; $number <= 99999 && $found === null; $number++) {
            $body = 'CL20261027' . $number;
            $shorter = 'CL20261027' . substr((string) $number, 0, 4);
            if (AccountReference::checkCharacter($shorter) === substr((string) $number, 4, 1)) $found = $body . AccountReference::checkCharacter($body);
        }
        self::assertNotNull($found, 'A reference whose first words make another valid one exists.');
        $spaced = implode(' ', explode('-', AccountReference::format($found)));
        self::assertSame([$found, false], BankPayments::accountReferenceIn($spaced), $spaced);
        self::assertSame([$found, false], BankPayments::accountReferenceIn('Payment ' . AccountReference::format($found) . ' thanks'));
        self::assertSame([$found, false], BankPayments::accountReferenceIn(strtolower($found) . ' June fees'));
    }
}
