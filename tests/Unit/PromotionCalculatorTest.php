<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Commerce\Domain\{OrderTotals, Promotion, PromotionCalculator, PromotionRejected};
use CattoLearning\Support\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Promotion rules and arithmetic, without a database: codes, windows, limits, minimum spend,
 * eligibility, percentage and fixed calculation, rounding and allocation over lines.
 */
final class PromotionCalculatorTest extends TestCase
{
    private const NOW = '2026-10-06T12:00:00+02:00';

    public function testCodesAreCaseInsensitiveAndHaveOneCanonicalForm(): void
    {
        foreach (['SAVE10', 'save10', 'Save10', '  sAvE10 '] as $typed) self::assertSame('SAVE10', Promotion::normaliseCode($typed));
        foreach (['SAVE10', 'WELCOME-2026', 'A_B', '100OFF'] as $valid) self::assertTrue(Promotion::isValidCode($valid), $valid);
        foreach (['', 'AB', 'save10', '-SAVE', 'SAVE 10', 'SAVE10!', str_repeat('A', 41), "SAVE10\n"] as $invalid) self::assertFalse(Promotion::isValidCode($invalid), $invalid);
    }

    public function testPercentagesRoundHalfUpToTheMinorUnit(): void
    {
        self::assertSame(12000, PromotionCalculator::percentageOf(120000, 1000), '10% of R1,200.00 is R120.00.');
        self::assertSame(200, PromotionCalculator::percentageOf(1995, 1000), '10% of 1995 is 199.5: half rounds up.');
        self::assertSame(199, PromotionCalculator::percentageOf(1994, 1000), '199.4 rounds down.');
        self::assertSame(1543, PromotionCalculator::percentageOf(12345, 1250), '12.5% of 12345 is 1543.125.');
        self::assertSame(1, PromotionCalculator::percentageOf(100, 50), '0.5% of 100 cents is half a cent: rounds up to one.');
        self::assertSame(0, PromotionCalculator::percentageOf(99, 50), '0.495 cents rounds to nothing.');
        self::assertSame(12345, PromotionCalculator::percentageOf(12345, 10000), '100% is the whole amount.');
        self::assertSame(0, PromotionCalculator::percentageOf(0, 10000));
    }

    public function testAPercentageDiscountsTheEligibleAmount(): void
    {
        $discount = $this->evaluate($this->promotion(), [$this->line(1, 10, 100000), $this->line(2, 20, 20000)]);
        self::assertSame([120000, 12000], [$discount->eligibleMinor, $discount->discountMinor], 'Subtotal R1,200.00, promo −R120.00, total R1,080.00.');
        self::assertSame(['course:1' => 10000, 'course:2' => 2000], $discount->lineDiscounts);
        self::assertSame([10, 20], $discount->eligibleCourseIds);
    }

    public function testAHundredPercentDiscountMakesTheOrderFree(): void
    {
        $discount = $this->evaluate($this->promotion(['discount_value' => 10000]), [$this->line(1, 10, 12345), $this->line(2, 20, 5000)]);
        self::assertSame(17345, $discount->discountMinor);
        self::assertSame(['course:1' => 12345, 'course:2' => 5000], $discount->lineDiscounts, 'Each line is discounted to exactly nothing.');
    }

    public function testAFixedAmountIsCappedAtTheEligibleAmount(): void
    {
        $fixed = ['discount_type' => Promotion::FIXED_AMOUNT, 'discount_value' => 10000, 'currency' => 'ZAR'];
        self::assertSame(10000, $this->evaluate($this->promotion($fixed), [$this->line(1, 10, 50000)])->discountMinor, 'R100 off R500.');
        $capped = $this->evaluate($this->promotion($fixed), [$this->line(1, 10, 6000), $this->line(2, 20, 1500)]);
        self::assertSame([7500, 7500], [$capped->eligibleMinor, $capped->discountMinor], 'R100 off R75 takes R75 off: the total is never negative.');
        self::assertSame(['course:1' => 6000, 'course:2' => 1500], $capped->lineDiscounts);
    }

    public function testAFixedAmountMustBeInTheOrderCurrency(): void
    {
        $this->expectRejection('That promo code cannot be used with this currency.', $this->promotion(['discount_type' => Promotion::FIXED_AMOUNT, 'discount_value' => 1000, 'currency' => 'USD']), [$this->line(1, 10, 50000)]);
    }

    public function testASelectedCoursePromotionDiscountsOnlyItsCourses(): void
    {
        $promotion = $this->promotion(['course_scope' => Promotion::SCOPE_SELECTED], [20]);
        $discount = $this->evaluate($promotion, [$this->line(1, 10, 100000), $this->line(2, 20, 50000), $this->line(3, 30, 25000)]);
        self::assertSame([50000, 5000], [$discount->eligibleMinor, $discount->discountMinor], 'Only the selected course counts; the others keep their full price.');
        self::assertSame(['course:2' => 5000], $discount->lineDiscounts);
        self::assertSame(0, $discount->lineDiscount('course:1'));
        $this->expectRejection('That promo code does not apply to anything in your cart.', $promotion, [$this->line(1, 10, 100000)]);
    }

    public function testTheDiscountIsSpreadInProportionWithTheRemainderAssignedDeterministically(): void
    {
        // 1735 over 12345 and 5000: exact shares 1234.79… and 500.20…; floors 1234 and 500 leave
        // one cent, which goes to the larger remainder.
        self::assertSame([1 => 1235, 2 => 500], PromotionCalculator::allocate(1735, [1 => 12345, 2 => 5000]));
        // Three equal lines and 100 cents: 33 each and one left; equal remainders, so the earliest line.
        self::assertSame([7 => 34, 8 => 33, 9 => 33], PromotionCalculator::allocate(100, [7 => 1000, 8 => 1000, 9 => 1000]));
        self::assertSame([9 => 34, 8 => 33, 7 => 33], PromotionCalculator::allocate(100, [9 => 1000, 8 => 1000, 7 => 1000]), 'Ties follow line order, not keys.');
        for ($i = 0; $i < 200; $i++) {
            $amounts = [];
            foreach (range(1, random_int(1, 8)) as $key) $amounts[$key] = random_int(1, 500000);
            $discount = random_int(0, array_sum($amounts));
            $shares = PromotionCalculator::allocate($discount, $amounts);
            self::assertSame($discount, array_sum($shares), 'The shares add up to the discount.');
            foreach ($shares as $key => $share) self::assertTrue($share >= 0 && $share <= $amounts[$key], 'No line is discounted beyond its price.');
            self::assertSame($shares, PromotionCalculator::allocate($discount, $amounts), 'The same input always gives the same allocation.');
        }
    }

    public function testEachLineIsEligibleByItsOwnProductType(): void
    {
        // A mixed cart: course 10 (R1,000) and bundle 7 (R1,200), which happens to contain course 10.
        $lines = [$this->line(1, 10, 100000), ['key' => 'bundle:3', 'product_type' => Promotion::PRODUCT_BUNDLE, 'product_id' => 7, 'amount_minor' => 120000]];
        $courseOnly = $this->evaluate($this->promotion(['course_scope' => Promotion::SCOPE_SELECTED], [10]), $lines);
        self::assertSame(['course:1' => 10000], $courseOnly->lineDiscounts, 'A promotion for course 10 does not discount a bundle because the bundle contains course 10.');
        self::assertSame([[10], []], [$courseOnly->eligibleCourseIds, $courseOnly->eligibleBundleIds]);
        $allCourses = $this->evaluate($this->promotion(), $lines);
        self::assertSame(['course:1' => 10000], $allCourses->lineDiscounts, 'All courses, no bundles.');
        $allBundles = $this->evaluate($this->promotion(['course_scope' => Promotion::SCOPE_NONE, 'bundle_scope' => Promotion::SCOPE_ALL]), $lines);
        self::assertSame(['bundle:3' => 12000], $allBundles->lineDiscounts, 'All bundles, no courses.');
        $selectedBundle = $this->evaluate($this->promotion(['course_scope' => Promotion::SCOPE_NONE, 'bundle_scope' => Promotion::SCOPE_SELECTED], [], [7]), $lines);
        self::assertSame([[], [7]], [$selectedBundle->eligibleCourseIds, $selectedBundle->eligibleBundleIds]);
        $this->expectRejection('That promo code does not apply to anything in your cart.', $this->promotion(['course_scope' => Promotion::SCOPE_NONE, 'bundle_scope' => Promotion::SCOPE_SELECTED], [], [8]), $lines);
        $everything = $this->evaluate($this->promotion(['bundle_scope' => Promotion::SCOPE_ALL]), $lines);
        self::assertSame([220000, 22000, ['course:1' => 10000, 'bundle:3' => 12000]], [$everything->eligibleMinor, $everything->discountMinor, $everything->lineDiscounts], 'All catalogue products: each line its proportional share.');
        self::assertSame(['All courses', 'All courses and bundles', '1 course and all bundles', 'All bundles'], [
            $this->promotion()->scopeLabel(), $this->promotion(['bundle_scope' => Promotion::SCOPE_ALL])->scopeLabel(),
            $this->promotion(['course_scope' => Promotion::SCOPE_SELECTED, 'bundle_scope' => Promotion::SCOPE_ALL], [10])->scopeLabel(),
            $this->promotion(['course_scope' => Promotion::SCOPE_NONE, 'bundle_scope' => Promotion::SCOPE_ALL])->scopeLabel(),
        ]);
    }

    public function testTheValidityWindowStartsInclusiveAndEndsExclusive(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $lines = [$this->line(1, 10, 10000)];
        self::assertSame(1000, $this->evaluate($this->promotion(['starts_at' => self::NOW]), $lines)->discountMinor, 'Valid at its start.');
        $this->expectRejection('That promo code is not valid yet.', $this->promotion(['starts_at' => $now->modify('+1 second')->format(DATE_ATOM)]), $lines);
        $this->expectRejection('That promo code has expired.', $this->promotion(['ends_at' => self::NOW]), $lines, 'No longer valid at its end.');
        self::assertSame(1000, $this->evaluate($this->promotion(['ends_at' => $now->modify('+1 second')->format(DATE_ATOM)]), $lines)->discountMinor, 'Valid until its end.');
        self::assertSame('ended', $this->promotion(['ends_at' => self::NOW, 'active' => false])->status($now));
        self::assertSame('inactive', $this->promotion(['active' => false])->status($now));
        self::assertSame('scheduled', $this->promotion(['starts_at' => '2026-10-07T00:00:00+02:00'])->status($now));
        self::assertSame('active', $this->promotion()->status($now));
    }

    public function testEveryRuleExplainsItsRejection(): void
    {
        $lines = [$this->line(1, 10, 10000)];
        $this->expectRejection('That promo code is not currently available.', $this->promotion(['active' => false]), $lines);
        $this->expectRejection('That promo code has reached its usage limit.', $this->promotion(['maximum_total_uses' => 100]), $lines, '', 100);
        self::assertSame(1000, $this->evaluate($this->promotion(['maximum_total_uses' => 100]), $lines, 99)->discountMinor, 'The hundredth use is allowed.');
        $this->expectRejection('You have already used that promo code as many times as it allows. An unpaid order that uses it counts until it is paid or cancelled.', $this->promotion(['maximum_uses_per_customer' => 1]), $lines, '', 5, 1);
        $this->expectRejection('That promo code needs at least ' . self::rand(10001) . ' of eligible items in your cart.', $this->promotion(['minimum_order_minor' => 10001, 'currency' => 'ZAR']), $lines);
        self::assertSame(1000, $this->evaluate($this->promotion(['minimum_order_minor' => 10000, 'currency' => 'ZAR']), $lines)->discountMinor, 'The minimum is met by the eligible amount before the discount.');
        $this->expectRejection('That promo code gives no discount on this order.', $this->promotion(['discount_value' => 1]), [$this->line(1, 10, 100)]);
    }

    public function testTheMinimumSpendCountsEligibleCoursesOnly(): void
    {
        $promotion = $this->promotion(['course_scope' => Promotion::SCOPE_SELECTED, 'minimum_order_minor' => 100000, 'currency' => 'ZAR'], [10]);
        $this->expectRejection('That promo code needs at least ' . self::rand(100000) . ' of eligible items in your cart.', $promotion, [$this->line(1, 10, 60000), $this->line(2, 20, 90000)]);
        self::assertSame(10000, $this->evaluate($promotion, [$this->line(1, 10, 100000), $this->line(2, 20, 90000)])->discountMinor);
    }

    public function testLabelsAndTotalsShowTheSubtotalDiscountAndTotal(): void
    {
        self::assertSame('10% off', $this->promotion()->discountLabel('en_ZA'));
        self::assertSame(['12.5', '0.01', '100'], [Promotion::percentText(1250), Promotion::percentText(1), Promotion::percentText(10000)]);
        self::assertSame([['label' => 'Total', 'value' => self::rand(108000)]], OrderTotals::rows(120000, 0, 108000, 'ZAR'), 'Without a discount, only the total.');
        self::assertSame([
            ['label' => 'Subtotal', 'value' => self::rand(120000)],
            ['label' => 'Promo SAVE10 (10% off)', 'value' => '−' . self::rand(12000)],
            ['label' => 'Total', 'value' => self::rand(108000)],
        ], OrderTotals::rows(120000, 12000, 108000, 'ZAR', 'SAVE10', '10% off'), 'The subtotal stays visible above the promotion and the total.');
    }

    private static function rand(int $minor): string
    {
        return Money::strictMinorUnits($minor, 'ZAR')->format();
    }

    /**
     * @param array<string,mixed> $overrides
     * @param list<int> $courses
     * @param list<int> $bundles
     */
    private function promotion(array $overrides = [], array $courses = [], array $bundles = []): Promotion
    {
        return Promotion::fromRow($overrides + [
            'id' => 7, 'code' => 'SAVE10', 'name' => 'Ten off', 'description' => null, 'discount_type' => Promotion::PERCENTAGE, 'discount_value' => 1000,
            'currency' => null, 'starts_at' => null, 'ends_at' => null, 'active' => true, 'minimum_order_minor' => null,
            'maximum_total_uses' => null, 'maximum_uses_per_customer' => null, 'course_scope' => Promotion::SCOPE_ALL, 'bundle_scope' => Promotion::SCOPE_NONE,
        ], $courses, $bundles);
    }

    /** @return array{key:string,product_type:string,product_id:int,amount_minor:int} a course line keyed "course:<key>" */
    private function line(int $key, int $course, int $amount): array
    {
        return ['key' => 'course:' . $key, 'product_type' => Promotion::PRODUCT_COURSE, 'product_id' => $course, 'amount_minor' => $amount];
    }

    /** @param list<array{key:string,product_type:string,product_id:int,amount_minor:int}> $lines */
    private function evaluate(Promotion $promotion, array $lines, int $totalUses = 0, int $customerUses = 0): \CattoLearning\Commerce\Domain\PromotionDiscount
    {
        return PromotionCalculator::evaluate($promotion, $lines, 'ZAR', new DateTimeImmutable(self::NOW), $totalUses, $customerUses);
    }

    /** @param list<array{key:string,product_type:string,product_id:int,amount_minor:int}> $lines */
    private function expectRejection(string $message, Promotion $promotion, array $lines, string $why = '', int $totalUses = 0, int $customerUses = 0): void
    {
        try {
            $this->evaluate($promotion, $lines, $totalUses, $customerUses);
            self::fail('Expected a rejection: ' . $message);
        } catch (PromotionRejected $rejected) {
            self::assertSame($message, $rejected->getMessage(), $why);
            self::assertSame($promotion->code, $rejected->promoCode);
            self::assertStringNotContainsString((string) $promotion->id, $rejected->getMessage(), 'No internal id reaches the purchaser.');
        }
    }
}
