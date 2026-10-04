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
 * review_submitted, review_approved, landing_page_view, promo_code_applied, bundle_purchased.
 */
enum AnalyticsEventType: string
{
    case CourseView = 'course_view';
    case CourseFavouriteAdded = 'course_favourite_added';
    case CourseFavouriteRemoved = 'course_favourite_removed';
    case CheckoutStarted = 'checkout_started';
    case CoursePurchased = 'course_purchased';
    case CourseRefunded = 'course_refunded';

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
        };
    }

    /** Business facts that must never be recorded twice carry an idempotency key. */
    public function requiresIdempotencyKey(): bool
    {
        return in_array($this, [self::CoursePurchased, self::CourseRefunded, self::CheckoutStarted], true);
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
            self::CoursePurchased => ['quantity' => 'int', 'access_period_seconds' => 'int', 'amount_minor' => 'int', 'currency' => 'string', 'purchaser' => 'string', 'company_id' => 'int'],
            self::CourseRefunded => ['refund_id' => 'int', 'quantity' => 'int', 'amount_minor' => 'int', 'currency' => 'string', 'full_refund' => 'bool', 'purchaser' => 'string', 'company_id' => 'int'],
        };
    }
}
