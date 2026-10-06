<?php

declare(strict_types=1);

namespace CattoLearning\Analytics;

use InvalidArgumentException;

/**
 * The analytics event vocabulary. Every event the platform records is one of these; nothing else
 * reaches the event stream. An event type also declares which references it needs and which
 * metadata keys it may carry, with their types, so metadata stays bounded and can never become a
 * place to copy a profile, a payment or a request into.
 *
 * Planned events are added here when the feature that produces them exists, not before:
 * landing_page_view.
 *
 * A bundle is its own product. bundle_purchased: a paid bundle order line, once per line, with what
 * was paid for it; bundle_refunded: a refund of one. Neither records course_purchased or
 * course_refunded for the bundle's courses: those courses were not sold one by one, and counting
 * them as sales would overstate each course's purchases, revenue and popularity.
 *
 * Promo codes have two events. promo_code_applied: a valid code was accepted into a cart at
 * checkout (an invalid or repeated code records nothing). promo_code_redeemed: an order placed with
 * a promotion was paid, recorded once per order whatever replays the payment.
 */
enum AnalyticsEventType: string
{
    case CourseView = 'course_view';
    case CourseFavouriteAdded = 'course_favourite_added';
    case CourseFavouriteRemoved = 'course_favourite_removed';
    case CheckoutStarted = 'checkout_started';
    case CoursePurchased = 'course_purchased';
    case CourseRefunded = 'course_refunded';
    case ReviewSubmitted = 'review_submitted';
    case ReviewUpdated = 'review_updated';
    case ReviewApproved = 'review_approved';
    case ReviewRejected = 'review_rejected';
    case PromoCodeApplied = 'promo_code_applied';
    case PromoCodeRedeemed = 'promo_code_redeemed';
    case BundlePurchased = 'bundle_purchased';
    case BundleRefunded = 'bundle_refunded';

    public static function fromName(string $name): self
    {
        return self::tryFrom($name) ?? throw new InvalidArgumentException('Unknown analytics event type: ' . $name);
    }

    /**
     * References an event of this type must carry.
     *
     * @return list<string>
     */
    public function requiredReferences(): array
    {
        return match ($this) {
            self::CourseView => ['course_id'],
            self::CourseFavouriteAdded, self::CourseFavouriteRemoved => ['user_id', 'course_id'],
            self::CheckoutStarted => ['user_id'],
            self::CoursePurchased, self::CourseRefunded => ['course_id', 'order_id', 'order_item_id'],
            // The reviewer is the user; the review itself is named in metadata.
            self::ReviewSubmitted, self::ReviewUpdated, self::ReviewApproved, self::ReviewRejected => ['user_id', 'course_id'],
            self::PromoCodeApplied => ['user_id'],
            self::PromoCodeRedeemed => ['user_id', 'order_id'],
            self::BundlePurchased, self::BundleRefunded => ['user_id', 'order_id', 'order_item_id'],
        };
    }

    /** Business facts that must never be recorded twice carry an idempotency key. */
    public function requiresIdempotencyKey(): bool
    {
        return in_array($this, [self::CoursePurchased, self::CourseRefunded, self::CheckoutStarted, self::ReviewSubmitted, self::ReviewUpdated, self::ReviewApproved, self::ReviewRejected, self::PromoCodeApplied, self::PromoCodeRedeemed, self::BundlePurchased, self::BundleRefunded], true);
    }

    /**
     * The metadata keys this event may carry and their types: int, bool, string (at most 64
     * characters) or int_list (at most 50 integers).
     *
     * @return array<string,'int'|'bool'|'string'|'int_list'>
     */
    public function metadataSchema(): array
    {
        return match ($this) {
            // Which page of the course was viewed; the reader's item views also carry the placement.
            self::CourseView => ['context' => 'string', 'node_id' => 'int'],
            self::CourseFavouriteAdded, self::CourseFavouriteRemoved => [],
            self::CheckoutStarted => ['cart_id' => 'int', 'line_count' => 'int', 'course_ids' => 'int_list', 'total_minor' => 'int', 'currency' => 'string', 'purchaser' => 'string', 'company_id' => 'int'],
            // amount_minor is what the line was paid: its price less its share of any promotion discount.
            self::CoursePurchased => ['quantity' => 'int', 'access_period_seconds' => 'int', 'amount_minor' => 'int', 'discount_minor' => 'int', 'currency' => 'string', 'purchaser' => 'string', 'company_id' => 'int'],
            self::CourseRefunded => ['refund_id' => 'int', 'quantity' => 'int', 'amount_minor' => 'int', 'currency' => 'string', 'full_refund' => 'bool', 'purchaser' => 'string', 'company_id' => 'int'],
            // Never the review text: its rating, revision and whether it has a comment.
            self::ReviewSubmitted, self::ReviewUpdated => ['review_id' => 'int', 'rating' => 'int', 'revision' => 'int', 'has_comment' => 'bool', 'previous_status' => 'string'],
            self::ReviewApproved, self::ReviewRejected => ['review_id' => 'int', 'rating' => 'int', 'revision' => 'int', 'moderator_id' => 'int', 'withdrawn' => 'bool'],
            // Never the promotion's description or the customer's details.
            self::PromoCodeApplied => ['promotion_id' => 'int', 'cart_id' => 'int', 'discount_type' => 'string', 'discount_minor' => 'int', 'eligible_minor' => 'int', 'currency' => 'string', 'course_ids' => 'int_list', 'bundle_ids' => 'int_list'],
            self::PromoCodeRedeemed => ['promotion_id' => 'int', 'discount_type' => 'string', 'discount_minor' => 'int', 'eligible_minor' => 'int', 'currency' => 'string', 'line_count' => 'int'],
            // amount_minor is what the line was paid; the courses it granted and those already held.
            self::BundlePurchased => ['bundle_id' => 'int', 'amount_minor' => 'int', 'discount_minor' => 'int', 'currency' => 'string', 'course_count' => 'int', 'courses_granted' => 'int', 'courses_already_held' => 'int', 'access_period_seconds' => 'int'],
            self::BundleRefunded => ['bundle_id' => 'int', 'refund_id' => 'int', 'amount_minor' => 'int', 'currency' => 'string', 'full_refund' => 'bool', 'courses_revoked' => 'int', 'courses_kept' => 'int'],
        };
    }
}
