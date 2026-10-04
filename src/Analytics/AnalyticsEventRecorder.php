<?php

declare(strict_types=1);

namespace CattoLearning\Analytics;

use CattoLearning\Support\Uuid;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records analytics events. Application code calls record() with an event type from the
 * vocabulary, a source, the entities the event concerns and any event-specific metadata; this
 * class validates all of it, stamps the time from the platform clock, and appends the event.
 *
 * Privacy by construction: references are ids, a visitor is a random per-session UUID, and
 * metadata is limited to the keys and types its event type declares, so a profile, payment detail,
 * token, address or request header cannot be recorded even by mistake.
 *
 * Business events (purchases, refunds, favourites) are recorded inside the same transaction as the
 * business change they describe, so the event exists exactly when the change does.
 */
final class AnalyticsEventRecorder
{
    private const REFERENCES = ['user_id', 'visitor_id', 'course_id', 'course_item_id', 'order_id', 'order_item_id'];
    private const MAX_STRING = 64;
    private const MAX_LIST = 50;
    /** Defence in depth beneath the per-type schemas: names that suggest personal or payment data. */
    private const FORBIDDEN_KEY = '/pass|token|secret|card|cvv|cvc|\bpan\b|iban|account_number|address|email|phone|mobile|\bip\b|ip_address|header|cookie|identification|session/i';

    public function __construct(private readonly AnalyticsEventRepository $events, private readonly ClockInterface $clock)
    {
    }

    /**
     * @param array{user_id?:int|null,visitor_id?:string|null,course_id?:int|null,course_item_id?:int|null,order_id?:int|null,order_item_id?:int|null} $references
     * @param array<string,mixed> $metadata
     * @return bool true when recorded, false when an event with the idempotency key already exists
     * @throws InvalidArgumentException for an unknown event type, a missing or malformed reference,
     *         metadata outside the event's schema, or a missing idempotency key where one is required
     */
    public function record(AnalyticsEventType|string $type, AnalyticsSource $source, array $references = [], array $metadata = [], ?string $idempotencyKey = null): bool
    {
        $type = $type instanceof AnalyticsEventType ? $type : AnalyticsEventType::fromName($type);
        $references = self::references($type, $references);
        $metadata = self::metadata($type, $metadata);
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 160)) {
            throw new InvalidArgumentException('An analytics idempotency key is 1 to 160 characters.');
        }
        if ($idempotencyKey === null && $type->requiresIdempotencyKey()) {
            throw new InvalidArgumentException($type->value . ' events need an idempotency key so they are never recorded twice.');
        }
        return $this->events->append($type->value, $source->value, $this->clock->now(), $references, $metadata, $idempotencyKey);
    }

    /**
     * For events that must never disturb what the visitor was doing (a page view, the start of a
     * checkout): a failure to record is logged and the request carries on. Not for use inside a
     * business transaction, where a failed statement would abort the transaction anyway.
     *
     * @param array{user_id?:int|null,visitor_id?:string|null,course_id?:int|null,course_item_id?:int|null,order_id?:int|null,order_item_id?:int|null} $references
     * @param array<string,mixed> $metadata
     */
    public function recordSafely(AnalyticsEventType $type, AnalyticsSource $source, array $references = [], array $metadata = [], ?string $idempotencyKey = null): bool
    {
        try {
            return $this->record($type, $source, $references, $metadata, $idempotencyKey);
        } catch (\Exception $exception) {
            error_log('Analytics event ' . $type->value . ' was not recorded: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * One view of a course page by a learner or a visitor. ADMIN previews are not views and never
     * reach here.
     */
    public function courseViewed(int $courseId, AnalyticsSource $source, ?int $userId, ?string $visitorId, string $context, ?int $nodeId = null, ?int $courseItemId = null): void
    {
        $this->recordSafely(AnalyticsEventType::CourseView, $source, ['course_id' => $courseId, 'user_id' => $userId, 'visitor_id' => $visitorId, 'course_item_id' => $courseItemId], ['context' => $context, 'node_id' => $nodeId]);
    }

    /**
     * @param array<string,mixed> $references
     * @return array<string,int|string|null>
     */
    private static function references(AnalyticsEventType $type, array $references): array
    {
        $unknown = array_diff(array_keys($references), self::REFERENCES);
        if ($unknown !== []) { throw new InvalidArgumentException('Unknown analytics reference: ' . implode(', ', $unknown)); }
        $clean = [];
        foreach (self::REFERENCES as $key) {
            $value = $references[$key] ?? null;
            if ($value === null) { $clean[$key] = null; continue; }
            if ($key === 'visitor_id') {
                if (!is_string($value) || !Uuid::isValid($value)) { throw new InvalidArgumentException('An analytics visitor is a random UUID.'); }
                $clean[$key] = strtolower($value);
                continue;
            }
            if (!is_int($value) || $value < 1) { throw new InvalidArgumentException('Analytics reference ' . $key . ' must be a positive id.'); }
            $clean[$key] = $value;
        }
        foreach ($type->requiredReferences() as $required) {
            if ($clean[$required] === null) { throw new InvalidArgumentException($type->value . ' events need ' . $required . '.'); }
        }
        return $clean;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function metadata(AnalyticsEventType $type, array $metadata): array
    {
        $schema = $type->metadataSchema();
        foreach ($metadata as $key => $value) {
            if (preg_match(self::FORBIDDEN_KEY, (string) $key) === 1) { throw new InvalidArgumentException('Analytics metadata may not hold ' . $key . '.'); }
            if (!isset($schema[$key])) { throw new InvalidArgumentException($type->value . ' metadata has no ' . $key . '.'); }
            if ($value === null) { unset($metadata[$key]); continue; }
            $valid = match ($schema[$key]) {
                'int' => is_int($value),
                'bool' => is_bool($value),
                'string' => is_string($value) && mb_strlen($value) <= self::MAX_STRING,
                'int_list' => is_array($value) && array_is_list($value) && count($value) <= self::MAX_LIST && array_filter($value, static fn(mixed $v): bool => !is_int($v)) === [],
            };
            if (!$valid) { throw new InvalidArgumentException($type->value . ' metadata ' . $key . ' must be ' . $schema[$key] . '.'); }
        }
        return $metadata;
    }
}
