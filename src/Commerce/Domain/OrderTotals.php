<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

use CattoLearning\Support\Money;

/**
 * The totals rows a cart, an order page or a financial document shows. Without a discount there is
 * only the total; with one, the subtotal stays visible above the promotion line and the total:
 *
 *     Subtotal                    R1,200.00
 *     Promo SAVE10 (10% off)       −R120.00
 *     Total                       R1,080.00
 */
final class OrderTotals
{
    /** @return list<array{label:string,value:string}> */
    public static function rows(int $subtotalMinor, int $discountMinor, int $totalMinor, string $currency, ?string $code = null, ?string $label = null): array
    {
        $format = static fn(int $minor): string => Money::strictMinorUnits($minor, $currency)->format();
        if ($discountMinor === 0) return [['label' => 'Total', 'value' => $format($totalMinor)]];
        return [
            ['label' => 'Subtotal', 'value' => $format($subtotalMinor)],
            ['label' => 'Promo ' . $code . ($label === null ? '' : ' (' . $label . ')'), 'value' => self::negative($discountMinor, $currency)],
            ['label' => 'Total', 'value' => $format($totalMinor)],
        ];
    }

    /**
     * Totals of a placed order from its immutable snapshot: the subtotal is the sum of its lines, the
     * discount is the promotion it was placed with. Nothing is read from the current promotion.
     *
     * @param array<string,mixed> $snapshot
     * @return list<array{label:string,value:string}>
     */
    public static function forSnapshot(array $snapshot): array
    {
        $subtotal = 0;
        foreach ((array) ($snapshot['items'] ?? []) as $item) $subtotal += (int) ($item['line_total_minor'] ?? 0);
        $promotion = is_array($snapshot['promotion'] ?? null) ? $snapshot['promotion'] : null;
        return self::rows($subtotal, (int) ($promotion['discount_minor'] ?? 0), (int) $snapshot['total_minor'], (string) $snapshot['currency'],
            $promotion === null ? null : (string) $promotion['code'], $promotion === null ? null : (string) $promotion['label']);
    }

    /** A discount as shown beside a total: "−R120.00". */
    public static function negative(int $minor, string $currency): string
    {
        return '−' . Money::strictMinorUnits($minor, $currency)->format();
    }
}
