<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Analytics\{AnalyticsEventRecorder, AnalyticsEventType, AnalyticsSource};
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Domain\{Promotion, PromotionCalculator, PromotionDiscount, PromotionRejected};
use CattoLearning\Commerce\Infrastructure\{CommerceRepository, PromotionRepository};
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Promo codes at individual checkout. A cart carries at most one promotion; applying a code
 * validates it against the cart now and, if it is accepted, replaces any code already applied (a
 * rejected code leaves the current one in place). The discount is always calculated here, from the
 * cart and the current promotion definition: the browser submits only the code.
 *
 * Automatic promotions (owner, 2026-10-20) need no code: whenever the cart is priced, each one that is
 * switched on is tried against it, and the cart says which applied ("SPRING25, applied automatically").
 * One promotion applies to an order, the one that saves the purchaser the most: a typed code that saves
 * less than an automatic promotion is refused when it is applied, and if the cart later changes so that
 * an automatic promotion saves more, the order uses that one and the cart says why. Guests' carts show
 * automatic promotions too, and course and bundle pages announce the ones that cover them.
 *
 * The promotion is evaluated again whenever the cart is priced, and once more at placement under
 * the row locks of every promotion it could use, which is where a use is taken. A use is redeemed when
 * the order is paid.
 */
final class PromotionService
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly CommerceRepository $records,
        private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock,
        private readonly AnalyticsEventRecorder $analytics,
    ) {}

    /**
     * The cart's promotion as it stands now: the code typed on it, if any, or the automatic promotion
     * that saves the most, whichever saves more. With $lock (placement only) every promotion row it
     * could use is locked first, in id order, so the usage read here cannot be overtaken before the
     * order is inserted.
     *
     * code is the promotion that applies (or the typed code that no longer does, with its rejection);
     * typed_code is the code the purchaser typed, kept on the cart even when an automatic promotion
     * saves more (note says so); automatic says the cart applied the promotion by itself.
     *
     * @param array<string,mixed> $cart a cart row with its items and bundles
     * @return array{code:?string,discount:?PromotionDiscount,rejection:?string,automatic:bool,typed_code:?string,note:?string}
     */
    public function forCart(array $cart, int $userId, bool $lock = false): array
    {
        $typedId = isset($cart['promotion_id']) ? (int) $cart['promotion_id'] : null;
        $items = (array) ($cart['items'] ?? []);
        $bundles = (array) ($cart['bundles'] ?? []);
        // An empty cart with no code typed has nothing to price: most page views, every one a guest's.
        if ($typedId === null && $items === [] && $bundles === []) return ['code' => null, 'discount' => null, 'rejection' => null, 'automatic' => false, 'typed_code' => null, 'note' => null];
        $typed = null;
        $typedDiscount = null;
        $rejection = null;
        $best = null;
        foreach ($this->promotions->automatic($typedId, $lock) as $promotion) {
            $isTyped = $promotion->id === $typedId;
            if ($isTyped) $typed = $promotion;
            if (!$isTyped && !$promotion->appliesAutomatically) continue;
            try {
                $discount = $this->evaluate($promotion, $items, $bundles, $userId);
            } catch (PromotionRejected $rejected) {
                if ($isTyped) $rejection = $rejected->getMessage();
                continue;
            }
            if ($isTyped) $typedDiscount = $discount;
            elseif ($best === null || $discount->discountMinor > $best->discountMinor) $best = $discount;
        }
        $result = ['code' => null, 'discount' => null, 'rejection' => null, 'automatic' => false, 'typed_code' => $typed?->code, 'note' => null];
        // A typed code that no longer applies is shown with its reason and stops placement until it is removed.
        if ($typed !== null && $rejection !== null) return ['code' => $typed->code, 'rejection' => $rejection] + $result;
        if ($typedDiscount !== null && ($best === null || $typedDiscount->discountMinor >= $best->discountMinor)) {
            return ['code' => $typed?->code, 'discount' => $typedDiscount] + $result;
        }
        if ($best === null) return $result;
        $note = $typedDiscount === null ? null
            : 'Your code ' . $typedDiscount->promotion->code . ' saves less than ' . $best->promotion->code . ', which is applied automatically, so your order uses ' . $best->promotion->code . '.';
        return ['code' => $best->promotion->code, 'discount' => $best->appliedAutomatically(), 'automatic' => true, 'note' => $note] + $result;
    }

    /**
     * A guest's cart: no code can be typed before signing in, so only the automatic promotion that saves
     * the most, judged as for a purchaser who has not used it yet.
     *
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $bundles
     * @return array{code:?string,discount:?PromotionDiscount,rejection:?string,automatic:bool,typed_code:?string,note:?string}
     */
    public function forGuest(array $items, array $bundles): array
    {
        return $this->forCart(['promotion_id' => null, 'items' => $items, 'bundles' => $bundles], 0);
    }

    /**
     * What a course or bundle page says about the automatic promotions that cover it (owner, 2026-10-20):
     * each one switched on, within its dates and limits, with what the default price comes to when the
     * course or bundle alone meets the promotion's minimum spend.
     *
     * @param 'course'|'bundle' $productType
     * @return list<array{code:string,name:string,label:string,price_label:?string,minimum_label:?string,until_label:?string}>
     */
    public function announcements(string $productType, int $productId, int $priceMinor, string $currency, ?int $userId = null): array
    {
        $announced = [];
        if ($priceMinor < 1) return $announced;
        $now = $this->clock->now();
        foreach ($this->promotions->automatic() as $promotion) {
            if (!$promotion->appliesAutomatically || !$promotion->appliesTo($productType, $productId)) continue;
            $usage = $this->promotions->usage($promotion->id, $userId ?? 0);
            $line = ['key' => $productType . ':' . $productId, 'product_type' => $productType, 'product_id' => $productId, 'amount_minor' => $priceMinor];
            $price = null;
            try {
                $price = $priceMinor - PromotionCalculator::evaluate($promotion, [$line], $currency, $now, $usage['total'], $usage['customer'])->discountMinor;
            } catch (PromotionRejected) {
                // Announced only when the minimum spend is all that stands in the way: the cart then applies it once it holds enough.
                if ($promotion->minimumOrderMinor === null || $promotion->minimumOrderMinor <= $priceMinor) continue;
                try {
                    PromotionCalculator::evaluate($promotion, [['amount_minor' => $promotion->minimumOrderMinor] + $line], $currency, $now, $usage['total'], $usage['customer']);
                } catch (PromotionRejected) {
                    continue;
                }
            }
            $until = $promotion->endsAt?->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $announced[] = [
                'code' => $promotion->code, 'name' => $promotion->name, 'label' => $promotion->discountLabel(),
                'price_label' => $price === null ? null : Money::strictMinorUnits($price, $currency)->format(),
                'minimum_label' => $price === null ? Money::strictMinorUnits((int) $promotion->minimumOrderMinor, $currency)->format() : null,
                // The last moment it applies: the end is exclusive, so a promotion ending at midnight ends the day before.
                'until_label' => $until?->modify('-1 second')->format('j F Y'),
            ];
        }
        return $announced;
    }

    public function apply(CurrentUser $actor, string $code): PromotionDiscount
    {
        OrderService::requireCapability($actor, 'COMMERCE.CART.MANAGE');
        $canonical = Promotion::normaliseCode($code);
        if ($canonical === '') throw new RuntimeException('Enter a promo code.');
        return $this->transactions->run(function () use ($actor, $canonical): PromotionDiscount {
            $this->records->lockPurchaser($actor->id);
            $cart = $this->records->cart($actor->id);
            $items = $this->records->cartItems((int) $cart['id']);
            $bundles = $this->records->cartBundles((int) $cart['id']);
            if ($items === [] && $bundles === []) throw new RuntimeException('Your cart is empty.');
            $promotion = Promotion::isValidCode($canonical) ? $this->promotions->findByCode($canonical) : null;
            if ($promotion === null) throw new PromotionRejected('We don’t recognise that promo code.');
            $discount = $this->evaluate($promotion, $items, $bundles, $actor->id);
            // One promotion applies to an order, the one that saves the most (owner, 2026-10-20).
            $automatic = $this->forCart(['promotion_id' => null, 'items' => $items, 'bundles' => $bundles], $actor->id)['discount'];
            if ($automatic !== null && $automatic->promotion->id !== $promotion->id && $automatic->discountMinor >= $discount->discountMinor) {
                throw new PromotionRejected($automatic->promotion->code . ', which is applied automatically, already saves you at least as much as ' . $promotion->code . ' would. One promotion applies to an order.', $promotion->code);
            }
            if ((int) ($cart['promotion_id'] ?? 0) === $promotion->id) return $discount;
            $this->records->setCartPromotion((int) $cart['id'], $promotion->id);
            $this->analytics->recordSafely(AnalyticsEventType::PromoCodeApplied, AnalyticsSource::Checkout, ['user_id' => $actor->id], [
                'promotion_id' => $promotion->id, 'cart_id' => (int) $cart['id'], 'discount_type' => $promotion->discountType,
                'discount_minor' => $discount->discountMinor, 'eligible_minor' => $discount->eligibleMinor, 'currency' => $discount->currency,
                'course_ids' => array_slice($discount->eligibleCourseIds, 0, 50), 'bundle_ids' => array_slice($discount->eligibleBundleIds, 0, 50),
            ], 'promo_code_applied:cart:' . (int) $cart['id'] . ':' . ((int) $cart['revision'] + 1));
            return $discount;
        });
    }

    /** Returns false when no code was applied. */
    public function remove(CurrentUser $actor): bool
    {
        OrderService::requireCapability($actor, 'COMMERCE.CART.MANAGE');
        return $this->transactions->run(function () use ($actor): bool {
            $this->records->lockPurchaser($actor->id);
            $cart = $this->records->cart($actor->id);
            if ($cart['promotion_id'] === null) return false;
            $this->records->setCartPromotion((int) $cart['id'], null);
            return true;
        });
    }

    /**
     * Records the redemption of a paid order's promotion, once: fulfilment calls this inside the
     * transaction that brought the order to paid, and a replayed confirmation or a later review
     * release finds the redemption already there.
     *
     * @param array<string,mixed> $order a commerce_orders row in the paid state
     */
    public function redeem(array $order): void
    {
        if (($order['promotion_id'] ?? null) === null) return;
        $promotion = (array) (CommerceRepository::decode((string) $order['snapshot'])['promotion'] ?? []);
        $orderId = (int) $order['id'];
        $recorded = $this->promotions->recordRedemption((int) $order['promotion_id'], $orderId, (int) $order['purchaser_user_id'],
            (string) $promotion['code'], (int) $order['discount_minor'], (string) $order['currency'], $this->clock->now()->format(DATE_ATOM));
        if (!$recorded) return;
        $this->analytics->recordSafely(AnalyticsEventType::PromoCodeRedeemed, AnalyticsSource::Checkout,
            ['user_id' => (int) $order['purchaser_user_id'], 'order_id' => $orderId],
            ['promotion_id' => (int) $order['promotion_id'], 'discount_type' => (string) ($promotion['discount_type'] ?? ''), 'discount_minor' => (int) $order['discount_minor'],
                'eligible_minor' => (int) ($promotion['eligible_minor'] ?? 0), 'currency' => (string) $order['currency'],
                'line_count' => count((array) ($promotion['eligible_course_ids'] ?? [])) + count((array) ($promotion['eligible_bundle_ids'] ?? []))],
            'promo_code_redeemed:order:' . $orderId);
    }

    /**
     * Course lines are keyed "course:<price variant id>" and bundle lines "bundle:<offer id>", the keys
     * placement reads each line's share by. Each line is judged by its own product type.
     *
     * @param list<array<string,mixed>> $items cart course lines (price variants with their course)
     * @param list<array<string,mixed>> $bundles cart bundle lines (bundle offers)
     */
    private function evaluate(Promotion $promotion, array $items, array $bundles, int $userId): PromotionDiscount
    {
        $lines = [];
        $currency = null;
        foreach ($items as $item) {
            $currency ??= (string) $item['currency_code'];
            $lines[] = ['key' => 'course:' . (int) $item['id'], 'product_type' => Promotion::PRODUCT_COURSE, 'product_id' => (int) $item['course_id'], 'amount_minor' => (int) $item['price_minor_units']];
        }
        foreach ($bundles as $bundle) {
            $currency ??= (string) $bundle['currency_code'];
            $lines[] = ['key' => 'bundle:' . (int) $bundle['id'], 'product_type' => Promotion::PRODUCT_BUNDLE, 'product_id' => (int) $bundle['bundle_id'], 'amount_minor' => (int) $bundle['price_minor_units']];
        }
        $usage = $this->promotions->usage($promotion->id, $userId);
        return PromotionCalculator::evaluate($promotion, $lines, $currency ?? Money::platformCurrency(), $this->clock->now(), $usage['total'], $usage['customer']);
    }
}
