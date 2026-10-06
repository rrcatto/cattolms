<?php

declare(strict_types=1);

namespace CattoLearning\Analytics;

use CattoLearning\Infrastructure\Persistence\Database;
use DateTimeImmutable;

/**
 * The analytics event stream: the one place that writes analytics_events, and the query layer that
 * later features (popularity, course and checkout reporting) build their aggregates on. Every
 * aggregate reads the raw events; nothing is collapsed into stored counters.
 */
final class AnalyticsEventRepository
{
    private const TIME = 'Y-m-d H:i:sP';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Appends one event. With an idempotency key, a second event with the same key is ignored.
     *
     * @param array{user_id?:int|null,visitor_id?:string|null,course_id?:int|null,course_item_id?:int|null,order_id?:int|null,order_item_id?:int|null} $references
     * @param array<string,mixed> $metadata
     * @return bool true when the event was recorded, false when its idempotency key already was
     */
    public function append(string $type, string $source, DateTimeImmutable $occurredAt, array $references, array $metadata, ?string $idempotencyKey): bool
    {
        $row = $this->db->fetchAssociative(
            'INSERT INTO analytics_events (event_type,source,occurred_at,user_id,visitor_id,course_id,course_item_id,order_id,order_item_id,metadata,idempotency_key)
             VALUES (:type,:source,:at,:user,:visitor,:course,:item,:order,:order_item,CAST(:metadata AS JSONB),:key)
             ON CONFLICT (idempotency_key) DO NOTHING RETURNING id',
            [
                'type' => $type, 'source' => $source, 'at' => $occurredAt->format(self::TIME),
                'user' => $references['user_id'] ?? null, 'visitor' => $references['visitor_id'] ?? null,
                'course' => $references['course_id'] ?? null, 'item' => $references['course_item_id'] ?? null,
                'order' => $references['order_id'] ?? null, 'order_item' => $references['order_item_id'] ?? null,
                'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'key' => $idempotencyKey,
            ]
        );
        return $row !== false;
    }

    /** Events of one type, optionally for one course, since a moment. */
    public function countEvents(AnalyticsEventType $type, DateTimeImmutable $since, ?int $courseId = null): int
    {
        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM analytics_events WHERE event_type=:type AND occurred_at >= :since' . ($courseId === null ? '' : ' AND course_id=:course'),
            ['type' => $type->value, 'since' => $since->format(self::TIME)] + ($courseId === null ? [] : ['course' => $courseId])
        );
    }

    /**
     * Distinct viewers of a course since a moment: signed-in people by user, anonymous visitors by
     * their per-session visitor id, and both together. A signed-in viewer counts once however many
     * sessions they used.
     *
     * @return array{users:int,anonymous_visitors:int,total:int}
     */
    public function uniqueViewers(int $courseId, DateTimeImmutable $since): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT COUNT(DISTINCT user_id) AS users,
                    COUNT(DISTINCT visitor_id) FILTER (WHERE user_id IS NULL) AS anonymous_visitors
               FROM analytics_events
              WHERE event_type = :type AND course_id = :course AND occurred_at >= :since",
            ['type' => AnalyticsEventType::CourseView->value, 'course' => $courseId, 'since' => $since->format(self::TIME)]
        ) ?: [];
        $users = (int) ($row['users'] ?? 0); $anonymous = (int) ($row['anonymous_visitors'] ?? 0);
        return ['users' => $users, 'anonymous_visitors' => $anonymous, 'total' => $users + $anonymous];
    }

    /**
     * Event counts per course for one type since a moment, highest first.
     *
     * @return list<array{course_id:int,events:int}>
     */
    public function countsByCourse(AnalyticsEventType $type, DateTimeImmutable $since, int $limit = 50): array
    {
        return array_map(static fn(array $row): array => ['course_id' => (int) $row['course_id'], 'events' => (int) $row['events']], $this->db->fetchAllAssociative(
            'SELECT course_id, COUNT(*) AS events FROM analytics_events
              WHERE event_type=:type AND occurred_at >= :since AND course_id IS NOT NULL
              GROUP BY course_id ORDER BY events DESC, course_id LIMIT :limit',
            ['type' => $type->value, 'since' => $since->format(self::TIME), 'limit' => max(1, $limit)]
        ));
    }

    /**
     * Event counts per type and calendar day (UTC) since a moment.
     *
     * @return list<array{day:string,event_type:string,events:int}>
     */
    public function countsByTypeAndDay(DateTimeImmutable $since): array
    {
        return array_map(static fn(array $row): array => ['day' => (string) $row['day'], 'event_type' => (string) $row['event_type'], 'events' => (int) $row['events']], $this->db->fetchAllAssociative(
            "SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') AS day, event_type, COUNT(*) AS events
               FROM analytics_events WHERE occurred_at >= :since
              GROUP BY 1, 2 ORDER BY 1 DESC, 2",
            ['since' => $since->format(self::TIME)]
        ));
    }

    /**
     * Event counts per type since a moment.
     *
     * @return array<string,int>
     */
    public function countsByType(DateTimeImmutable $since): array
    {
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT event_type, COUNT(*) AS events FROM analytics_events WHERE occurred_at >= :since GROUP BY event_type', ['since' => $since->format(self::TIME)]) as $row) {
            $counts[(string) $row['event_type']] = (int) $row['events'];
        }
        return $counts;
    }

    /**
     * Distinct viewers per course in [since, until), counting only views from the given sources.
     * Viewers are counted as uniqueViewers() counts them: signed-in people by user, anonymous
     * visitors by visitor id. Courses with no views are absent.
     *
     * @param list<AnalyticsSource> $sources
     * @return array<int,int> viewers keyed by course id
     */
    public function uniqueViewersByCourse(DateTimeImmutable $since, DateTimeImmutable $until, array $sources): array
    {
        if ($sources === []) { return []; }
        $viewers = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT course_id, COUNT(DISTINCT user_id) + COUNT(DISTINCT visitor_id) FILTER (WHERE user_id IS NULL) AS viewers
               FROM analytics_events
              WHERE event_type = :type AND occurred_at >= :since AND occurred_at < :until AND course_id IS NOT NULL
                AND source IN (:sources)
              GROUP BY course_id',
            ['type' => AnalyticsEventType::CourseView->value, 'since' => $since->format(self::TIME), 'until' => $until->format(self::TIME),
                'sources' => array_map(static fn(AnalyticsSource $source): string => $source->value, $sources)]
        ) as $row) {
            $viewers[(int) $row['course_id']] = (int) $row['viewers'];
        }
        return $viewers;
    }

    /**
     * Purchases and refunds per course in [since, until).
     *
     * purchases is the number of paid order lines (each one buying decision); purchased_units adds
     * up their quantities (a company credit line may buy many). refunded_units measures each refund
     * in units of the line it refunds: its amount over what the line was paid (its price less any
     * promotion discount) per unit, at most its quantity, so a partial refund of an individual course
     * is a fraction of one unit, a full refund of a discounted course is one unit, and a company
     * credit refund is the units it returned. A refund whose order line is gone counts its quantity.
     *
     * @return array<int,array{purchases:int,purchased_units:int,refunds:int,refunded_units:float}> keyed by course id
     */
    public function purchaseTotalsByCourse(DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $totals = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT e.course_id,
                    COUNT(*) FILTER (WHERE e.event_type = :purchased) AS purchases,
                    COALESCE(SUM(GREATEST(1, COALESCE((e.metadata->>'quantity')::int, 1))) FILTER (WHERE e.event_type = :purchased), 0) AS purchased_units,
                    COUNT(*) FILTER (WHERE e.event_type = :refunded) AS refunds,
                    COALESCE(SUM(CASE
                        WHEN oi.id IS NOT NULL AND (e.metadata->>'amount_minor') IS NOT NULL
                            THEN LEAST(GREATEST(1, COALESCE((e.metadata->>'quantity')::int, 1))::numeric,
                                       (e.metadata->>'amount_minor')::numeric * oi.quantity / NULLIF(oi.amount_minor - oi.discount_minor, 0))
                        ELSE GREATEST(1, COALESCE((e.metadata->>'quantity')::int, 1))::numeric
                    END) FILTER (WHERE e.event_type = :refunded), 0) AS refunded_units
               FROM analytics_events e
               LEFT JOIN commerce_order_items oi ON oi.id = e.order_item_id
              WHERE e.event_type IN (:purchased, :refunded) AND e.occurred_at >= :since AND e.occurred_at < :until AND e.course_id IS NOT NULL
              GROUP BY e.course_id",
            ['purchased' => AnalyticsEventType::CoursePurchased->value, 'refunded' => AnalyticsEventType::CourseRefunded->value,
                'since' => $since->format(self::TIME), 'until' => $until->format(self::TIME)]
        ) as $row) {
            $totals[(int) $row['course_id']] = ['purchases' => (int) $row['purchases'], 'purchased_units' => (int) $row['purchased_units'],
                'refunds' => (int) $row['refunds'], 'refunded_units' => (float) $row['refunded_units']];
        }
        return $totals;
    }

    /** How many people favourite a course now. The favourites table is the authority; events are history. */
    public function currentFavouriteCount(int $courseId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_favourites WHERE course_id=:course', ['course' => $courseId]);
    }

    /**
     * How many people favourite each course now, from the favourites table. Courses nobody
     * favourites are absent.
     *
     * @return array<int,int> keyed by course id
     */
    public function currentFavouriteCounts(): array
    {
        $counts = [];
        foreach ($this->db->fetchAllAssociative('SELECT course_id, COUNT(*) AS favourites FROM course_favourites GROUP BY course_id') as $row) {
            $counts[(int) $row['course_id']] = (int) $row['favourites'];
        }
        return $counts;
    }

    /**
     * The newest events for the ADMIN event list, with the course title and person name joined in.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(?string $type, ?int $courseId, int $limit, int $offset): array
    {
        [$where, $bindings] = self::filter($type, $courseId);
        return $this->db->fetchAllAssociative(
            "SELECT e.id, e.event_type, e.source, e.occurred_at, e.user_id, e.visitor_id, e.course_id, e.course_item_id,
                    e.order_id, e.order_item_id, e.metadata::text AS metadata, e.idempotency_key,
                    c.title AS course_title, u.display_name AS user_name
               FROM analytics_events e
               LEFT JOIN courses c ON c.id = e.course_id
               LEFT JOIN users u ON u.id = e.user_id
              {$where}
              ORDER BY e.occurred_at DESC, e.id DESC LIMIT :limit OFFSET :offset",
            $bindings + ['limit' => max(1, $limit), 'offset' => max(0, $offset)]
        );
    }

    public function recentCount(?string $type, ?int $courseId): int
    {
        [$where, $bindings] = self::filter($type, $courseId);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events e ' . $where, $bindings);
    }

    /**
     * Retention: events before a moment lose the people they referred to but keep the facts.
     *
     * @return int events anonymised
     */
    public function anonymiseBefore(DateTimeImmutable $before): int
    {
        return $this->db->executeStatement(
            'UPDATE analytics_events SET user_id=NULL, visitor_id=NULL WHERE occurred_at < :before AND (user_id IS NOT NULL OR visitor_id IS NOT NULL)',
            ['before' => $before->format(self::TIME)]
        );
    }

    /** Retention: removes events before a moment. */
    public function deleteBefore(DateTimeImmutable $before): int
    {
        return $this->db->executeStatement('DELETE FROM analytics_events WHERE occurred_at < :before', ['before' => $before->format(self::TIME)]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filter(?string $type, ?int $courseId): array
    {
        $conditions = []; $bindings = [];
        if ($type !== null) { $conditions[] = 'e.event_type = :type'; $bindings['type'] = $type; }
        if ($courseId !== null) { $conditions[] = 'e.course_id = :course'; $bindings['course'] = $courseId; }
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $bindings];
    }
}
