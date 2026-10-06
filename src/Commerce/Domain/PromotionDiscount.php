<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

/**
 * What one promotion gives one cart: the eligible amount it was calculated on, the discount, and
 * that discount spread over the eligible lines. Calculated by the server from the cart and the
 * promotion alone; nothing a browser submits other than the code reaches it.
 */
final readonly class PromotionDiscount
{
    public const ALLOCATION = 'proportional_largest_remainder';

    /**
     * @param array<string,int> $lineDiscounts discount per line, keyed by the line's key ("course:<variant id>" or "bundle:<offer id>")
     * @param list<int> $eligibleCourseIds
     * @param list<int> $eligibleBundleIds
     */
    public function __construct(
        public Promotion $promotion,
        public string $currency,
        public int $eligibleMinor,
        public int $discountMinor,
        public array $lineDiscounts,
        public array $eligibleCourseIds,
        public array $eligibleBundleIds,
    ) {}

    public function lineDiscount(string $key): int
    {
        return $this->lineDiscounts[$key] ?? 0;
    }

    /**
     * The promotion as it was applied, frozen into the order. Everything needed to reproduce the
     * calculation is here, so the order never needs the current promotion definition again.
     *
     * @return array<string,mixed>
     */
    public function toSnapshot(): array
    {
        $promotion = $this->promotion;
        return [
            'promotion_id' => $promotion->id,
            'code' => $promotion->code,
            'name' => $promotion->name,
            'discount_type' => $promotion->discountType,
            'discount_value' => $promotion->discountValue,
            'label' => $promotion->discountLabel(),
            'course_scope' => $promotion->courseScope,
            'bundle_scope' => $promotion->bundleScope,
            'eligible_course_ids' => $this->eligibleCourseIds,
            'eligible_bundle_ids' => $this->eligibleBundleIds,
            'minimum_order_minor' => $promotion->minimumOrderMinor,
            'starts_at' => $promotion->startsAt?->format(DATE_ATOM),
            'ends_at' => $promotion->endsAt?->format(DATE_ATOM),
            'currency' => $this->currency,
            'eligible_minor' => $this->eligibleMinor,
            'discount_minor' => $this->discountMinor,
            'allocation' => self::ALLOCATION,
        ];
    }

    /**
     * What a checkout quote depends on: if any of this changes between showing the checkout and
     * placing the order, the purchaser reviews the new figures first.
     *
     * @return array<string,mixed>
     */
    public function quoteKey(): array
    {
        return ['promotion' => $this->promotion->id, 'code' => $this->promotion->code, 'discount' => $this->discountMinor, 'lines' => $this->lineDiscounts];
    }
}
