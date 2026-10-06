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
 * The promotion is evaluated again whenever the cart is priced, and once more at placement under
 * the promotion's row lock, which is where a use is taken. A use is redeemed when the order is paid.
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
     * The cart's promotion as it stands now. With $lock (placement only) the promotion row is
     * locked first, so the usage read here cannot be overtaken before the order is inserted.
     *
     * @param array<string,mixed> $cart a cart row with its items and bundles
     * @return array{code:?string,discount:?PromotionDiscount,rejection:?string}
     */
    public function forCart(array $cart, int $userId, bool $lock = false): array
    {
        $id = $cart['promotion_id'] ?? null;
        if ($id === null) return ['code' => null, 'discount' => null, 'rejection' => null];
        $promotion = $this->promotions->find((int) $id, $lock);
        if ($promotion === null) return ['code' => null, 'discount' => null, 'rejection' => null];
        try {
            return ['code' => $promotion->code, 'discount' => $this->evaluate($promotion, (array) $cart['items'], (array) ($cart['bundles'] ?? []), $userId), 'rejection' => null];
        } catch (PromotionRejected $rejected) {
            return ['code' => $promotion->code, 'discount' => null, 'rejection' => $rejected->getMessage()];
        }
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
