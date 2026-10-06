<?php

declare(strict_types=1);

namespace CattoLearning\Bundle;

use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;

/**
 * Bundles, their ordered courses and their offer. A bundle row is always read with its offer
 * (price_minor_units, currency_code, access_period_seconds, offer_active; null until one is saved).
 */
final class BundleRepository
{
    private const WITH_OFFER = 'SELECT b.*, o.id AS offer_id, o.price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active
        FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id = b.id';

    public function __construct(private readonly Database $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $lock = false): ?array
    {
        return $this->db->fetchAssociative(self::WITH_OFFER . ' WHERE b.id = :id' . ($lock ? ' FOR UPDATE OF b' : ''), ['id' => $id]) ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchAssociative(self::WITH_OFFER . ' WHERE b.slug = :slug', ['slug' => $slug]) ?: null;
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM bundles WHERE slug = :slug AND id IS DISTINCT FROM :id', ['slug' => $slug, 'id' => $exceptId]);
    }

    /** @param array<string,mixed> $fields bundles columns */
    public function create(array $fields, int $actorId, string $now): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO bundles(public_id,slug,title,short_description,description_html,cover_svg,available_from,available_until,created_at,updated_at,created_by_user_id,updated_by_user_id)
             VALUES (:public,:slug,:title,:short,:description,:cover,:from,:until,:now,:now,:actor,:actor) RETURNING id',
            self::bind($fields) + ['public' => Uuid::v4(), 'now' => $now, 'actor' => $actorId]
        );
    }

    /** @param array<string,mixed> $fields bundles columns */
    public function update(int $id, array $fields, int $actorId, string $now): void
    {
        $this->db->executeStatement(
            'UPDATE bundles SET slug=:slug,title=:title,short_description=:short,description_html=:description,cover_svg=:cover,available_from=:from,available_until=:until,
                updated_at=:now,updated_by_user_id=:actor WHERE id=:id',
            self::bind($fields) + ['id' => $id, 'now' => $now, 'actor' => $actorId]
        );
    }

    public function setStatus(int $id, string $status, int $actorId, string $now): void
    {
        $this->db->executeStatement(
            "UPDATE bundles SET status=:status, published_at=CASE WHEN :status='published' THEN COALESCE(published_at,:now) ELSE published_at END, updated_at=:now, updated_by_user_id=:actor WHERE id=:id",
            ['id' => $id, 'status' => $status, 'now' => $now, 'actor' => $actorId]
        );
    }

    public function delete(int $id): void
    {
        $this->db->executeStatement('DELETE FROM bundles WHERE id=:id', ['id' => $id]);
    }

    /**
     * The bundle's courses in their order, with what the catalogue and the price comparison need.
     *
     * @return list<array<string,mixed>>
     */
    public function courses(int $bundleId): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT bc.position, c.id AS course_id, c.title, c.slug, c.status,
                    v.price_minor_units AS default_price_minor_units, v.currency_code AS default_currency_code
               FROM bundle_courses bc
               JOIN courses c ON c.id = bc.course_id
               LEFT JOIN course_price_variants v ON v.course_id = c.id AND v.is_active AND v.is_default
              WHERE bc.bundle_id = :id ORDER BY bc.position",
            ['id' => $bundleId]
        );
    }

    public function hasCourse(int $bundleId, int $courseId): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM bundle_courses WHERE bundle_id=:b AND course_id=:c', ['b' => $bundleId, 'c' => $courseId]);
    }

    /** @return array<string,mixed>|null */
    public function course(int $courseId): ?array
    {
        return $this->db->fetchAssociative('SELECT id, title, slug, status FROM courses WHERE id=:id', ['id' => $courseId]) ?: null;
    }

    public function addCourse(int $bundleId, int $courseId): void
    {
        $this->db->executeStatement(
            'INSERT INTO bundle_courses(bundle_id,course_id,position) VALUES (:b,:c,(SELECT COALESCE(MAX(position),0)+1 FROM bundle_courses WHERE bundle_id=:b))',
            ['b' => $bundleId, 'c' => $courseId]
        );
    }

    /** Removes a course and closes the gap it leaves in the order. */
    public function removeCourse(int $bundleId, int $courseId): void
    {
        $this->db->executeStatement('DELETE FROM bundle_courses WHERE bundle_id=:b AND course_id=:c', ['b' => $bundleId, 'c' => $courseId]);
        $this->db->executeStatement(
            'UPDATE bundle_courses bc SET position = ranked.n FROM (SELECT course_id, ROW_NUMBER() OVER (ORDER BY position) AS n FROM bundle_courses WHERE bundle_id=:b) ranked
              WHERE bc.bundle_id=:b AND bc.course_id = ranked.course_id AND bc.position <> ranked.n',
            ['b' => $bundleId]
        );
    }

    /** Swaps a course with its neighbour; returns false at either end. */
    public function moveCourse(int $bundleId, int $courseId, string $direction): bool
    {
        $position = $this->db->fetchOne('SELECT position FROM bundle_courses WHERE bundle_id=:b AND course_id=:c', ['b' => $bundleId, 'c' => $courseId]);
        if ($position === false) return false;
        $neighbour = $this->db->fetchAssociative(
            'SELECT course_id, position FROM bundle_courses WHERE bundle_id=:b AND position ' . ($direction === 'up' ? '< :p ORDER BY position DESC' : '> :p ORDER BY position') . ' LIMIT 1',
            ['b' => $bundleId, 'p' => $position]
        );
        if ($neighbour === false) return false;
        $this->db->executeStatement('SET CONSTRAINTS bundle_courses_position_key DEFERRED');
        $this->db->executeStatement('UPDATE bundle_courses SET position=:p WHERE bundle_id=:b AND course_id=:c', ['p' => $neighbour['position'], 'b' => $bundleId, 'c' => $courseId]);
        $this->db->executeStatement('UPDATE bundle_courses SET position=:p WHERE bundle_id=:b AND course_id=:c', ['p' => $position, 'b' => $bundleId, 'c' => $neighbour['course_id']]);
        return true;
    }

    /** Saves the bundle's one offer in place; orders keep the price they were placed at in their snapshot. */
    public function saveOffer(int $bundleId, int $priceMinor, string $currency, int $periodSeconds, bool $active, int $actorId, string $now): void
    {
        $this->db->executeStatement(
            'INSERT INTO bundle_offers(public_id,bundle_id,price_minor_units,currency_code,access_period_seconds,is_active,created_at,updated_at,created_by_user_id,updated_by_user_id)
             VALUES (:public,:bundle,:price,:currency,:period,:active,:now,:now,:actor,:actor)
             ON CONFLICT (bundle_id) DO UPDATE SET price_minor_units=EXCLUDED.price_minor_units, currency_code=EXCLUDED.currency_code,
                access_period_seconds=EXCLUDED.access_period_seconds, is_active=EXCLUDED.is_active, updated_at=EXCLUDED.updated_at, updated_by_user_id=EXCLUDED.updated_by_user_id',
            ['public' => Uuid::v4(), 'bundle' => $bundleId, 'price' => $priceMinor, 'currency' => $currency, 'period' => $periodSeconds, 'active' => $active ? 't' : 'f', 'now' => $now, 'actor' => $actorId]
        );
    }

    /** Whether any order line, in any state, sold the bundle: such a bundle is kept for its history. */
    public function sold(int $bundleId): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM commerce_order_items WHERE bundle_id=:id LIMIT 1', ['id' => $bundleId]);
    }

    public function orderCount(int $bundleId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_order_items WHERE bundle_id=:id', ['id' => $bundleId]);
    }

    /**
     * Every order line that sold the bundle, newest first: the price it was sold at, its promotion
     * share and the order's state.
     *
     * @return list<array<string,mixed>>
     */
    public function sales(int $bundleId, int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT o.id AS order_id, o.state, o.placed_at, o.currency, i.id AS item_id, i.amount_minor, i.discount_minor, u.display_name AS purchaser_name
               FROM commerce_order_items i JOIN commerce_orders o ON o.id=i.order_id JOIN users u ON u.id=o.purchaser_user_id
              WHERE i.bundle_id=:id ORDER BY o.id DESC LIMIT :limit OFFSET :offset',
            ['id' => $bundleId, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * @param array{search?:string,status?:string} $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = self::where($filters);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id' . $where, $params);
    }

    /**
     * The ADMIN list: each bundle with its offer, number of courses and number of sales.
     *
     * @param array{search?:string,status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::where($filters);
        return $this->db->fetchAllAssociative(
            'SELECT b.*, o.price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
                    (SELECT COUNT(*) FROM bundle_courses bc WHERE bc.bundle_id=b.id) AS course_count,
                    (SELECT COUNT(*) FROM commerce_order_items i WHERE i.bundle_id=b.id) AS sales
               FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id' . $where . ' ORDER BY b.updated_at DESC, b.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    public function publishedCount(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM bundles WHERE status='published'");
    }

    /**
     * Published bundles for the public catalogue, newest first, with their offer and course count.
     *
     * @return list<array<string,mixed>>
     */
    public function published(int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT b.*, o.id AS offer_id, o.price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
                    (SELECT COUNT(*) FROM bundle_courses bc WHERE bc.bundle_id=b.id) AS course_count
               FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id
              WHERE b.status='published' ORDER BY b.published_at DESC NULLS LAST, b.title, b.id LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Which of these courses the learner already has open access to.
     *
     * @param list<int> $courseIds
     * @return list<int>
     */
    public function ownedCourseIds(int $userId, array $courseIds): array
    {
        if ($courseIds === []) return [];
        return array_map('intval', $this->db->fetchFirstColumn(
            "SELECT DISTINCT course_id FROM course_enrolments WHERE user_id=:user AND NOT is_preview AND status IN ('assigned','active','completed') AND course_id = ANY(CAST(:ids AS BIGINT[]))",
            ['user' => $userId, 'ids' => '{' . implode(',', array_map('intval', $courseIds)) . '}']
        ));
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function bind(array $fields): array
    {
        return ['slug' => $fields['slug'], 'title' => $fields['title'], 'short' => $fields['short_description'], 'description' => $fields['description_html'],
            'cover' => $fields['cover_svg'], 'from' => $fields['available_from'], 'until' => $fields['available_until']];
    }

    /**
     * @param array{search?:string,status?:string} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function where(array $filters): array
    {
        $clauses = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $clauses[] = '(b.title ILIKE :search OR b.slug ILIKE :search)';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $condition = match ((string) ($filters['status'] ?? '')) {
            'draft' => "b.status='draft'",
            'published' => "b.status='published' AND COALESCE(o.is_active,false)",
            'off_sale' => "b.status='published' AND NOT COALESCE(o.is_active,false)",
            'retired' => "b.status='retired'",
            default => null,
        };
        if ($condition !== null) $clauses[] = $condition;
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }
}
