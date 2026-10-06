<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

use CattoLearning\Support\Money;
use DateTimeImmutable;
use OverflowException;

/**
 * Promotion rules and arithmetic, in integer minor units only.
 *
 * - Eligible amount: the sum of the prices of the lines the promotion applies to, judged by each
 *   line's own product type and id (a course or a bundle). Lines it does not apply to keep their
 *   full price; a bundle is not eligible because it contains an eligible course.
 * - Percentage: eligible amount × basis points / 10 000, rounded half up to the minor unit.
 * - Fixed amount: the configured amount, capped at the eligible amount, so a total never goes below
 *   zero. The promotion's currency must be the order's.
 * - Minimum spend: compared with the eligible amount before any discount.
 * - Allocation: the discount is spread over the eligible lines in proportion to their prices. Each
 *   line first gets the floor of its exact share; the cents left over (fewer than the number of
 *   lines) go one each to the lines with the largest remainders, earlier lines first on a tie.
 *   The shares always add up to the discount, and no line is discounted beyond its price.
 */
final class PromotionCalculator
{
    /**
     * @param list<array{key:string,product_type:string,product_id:int,amount_minor:int}> $lines the cart's priced lines, in cart order
     * @throws PromotionRejected when the promotion cannot be applied
     */
    public static function evaluate(Promotion $promotion, array $lines, string $currency, DateTimeImmutable $now, int $totalUses, int $customerUses): PromotionDiscount
    {
        $code = $promotion->code;
        if (!$promotion->active) throw new PromotionRejected('That promo code is not currently available.', $code);
        $window = $promotion->window($now);
        if ($window === 'scheduled') throw new PromotionRejected('That promo code is not valid yet.', $code);
        if ($window === 'ended') throw new PromotionRejected('That promo code has expired.', $code);
        if ($promotion->maximumTotalUses !== null && $totalUses >= $promotion->maximumTotalUses) throw new PromotionRejected('That promo code has reached its usage limit.', $code);
        if ($promotion->maximumUsesPerCustomer !== null && $customerUses >= $promotion->maximumUsesPerCustomer) {
            throw new PromotionRejected('You have already used that promo code as many times as it allows. An unpaid order that uses it counts until it is paid or cancelled.', $code);
        }
        if ($promotion->currency !== null && $promotion->currency !== $currency) throw new PromotionRejected('That promo code cannot be used with this currency.', $code);

        $eligible = [];
        $products = [Promotion::PRODUCT_COURSE => [], Promotion::PRODUCT_BUNDLE => []];
        $eligibleMinor = 0;
        foreach ($lines as $line) {
            if ($line['amount_minor'] < 1 || !$promotion->appliesTo($line['product_type'], $line['product_id'])) continue;
            $eligible[$line['key']] = $line['amount_minor'];
            $products[$line['product_type']][] = $line['product_id'];
            $eligibleMinor = Money::strictMinorUnits($eligibleMinor, $currency)->plus(Money::strictMinorUnits($line['amount_minor'], $currency))->minorUnits;
        }
        if ($eligible === []) throw new PromotionRejected('That promo code does not apply to anything in your cart.', $code);
        if ($promotion->minimumOrderMinor !== null && $eligibleMinor < $promotion->minimumOrderMinor) {
            throw new PromotionRejected('That promo code needs at least ' . Money::strictMinorUnits($promotion->minimumOrderMinor, $currency)->format() . ' of eligible items in your cart.', $code);
        }

        $discount = $promotion->discountType === Promotion::PERCENTAGE
            ? self::percentageOf($eligibleMinor, $promotion->discountValue)
            : min($promotion->discountValue, $eligibleMinor);
        if ($discount < 1) throw new PromotionRejected('That promo code gives no discount on this order.', $code);

        return new PromotionDiscount($promotion, $currency, $eligibleMinor, $discount, self::allocate($discount, $eligible),
            array_values(array_unique($products[Promotion::PRODUCT_COURSE])), array_values(array_unique($products[Promotion::PRODUCT_BUNDLE])));
    }

    /** The percentage of an amount, rounded half up: 10% (1000 bp) of 1995 is 199.5, so 200. */
    public static function percentageOf(int $amountMinor, int $basisPoints): int
    {
        if ($amountMinor < 0 || $basisPoints < 0 || $basisPoints > 10000) throw new \InvalidArgumentException('Invalid amount or percentage.');
        if ($amountMinor > intdiv(PHP_INT_MAX - 5000, 10000)) throw new OverflowException('The amount is too large for a percentage discount.');
        return intdiv($amountMinor * $basisPoints + 5000, 10000);
    }

    /**
     * Spreads a discount over lines in proportion to their amounts (largest remainder method).
     *
     * @param array<array-key,int> $amounts line amounts keyed by line, in line order
     * @return array<array-key,int> the discount per line, same keys and order
     */
    public static function allocate(int $discount, array $amounts): array
    {
        $total = array_sum($amounts);
        if ($discount < 0 || $discount > $total) throw new \InvalidArgumentException('A discount cannot exceed the amount it is spread over.');
        $shares = [];
        $remainders = [];
        $position = 0;
        foreach ($amounts as $key => $amount) {
            if ($discount > 0 && $amount > intdiv(PHP_INT_MAX, $discount)) throw new OverflowException('The amount is too large to allocate a discount over.');
            $shares[$key] = $total === 0 ? 0 : intdiv($discount * $amount, $total);
            $remainders[] = ['key' => $key, 'remainder' => $total === 0 ? 0 : ($discount * $amount) % $total, 'position' => $position++];
        }
        $left = $discount - array_sum($shares);
        usort($remainders, static fn(array $a, array $b): int => [$b['remainder'], $a['position']] <=> [$a['remainder'], $b['position']]);
        foreach ($remainders as $entry) {
            if ($left === 0) break;
            $shares[$entry['key']]++;
            $left--;
        }
        return $shares;
    }
}
