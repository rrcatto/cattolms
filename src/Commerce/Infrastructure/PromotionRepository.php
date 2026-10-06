<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Infrastructure;

use CattoLearning\Commerce\Domain\Promotion;
use CattoLearning\Infrastructure\Persistence\Database;

/**
 * Promotions, their selected courses and their redemptions.
 *
 * A use counts against a promotion's limits from the moment an order is placed with it until that
 * order is cancelled: placed, awaiting payment, in review, paid, fulfilled and refunded orders all
 * count. Redemptions are the paid subset, recorded once per order.
 */
final class PromotionRepository
{
    /** Order states that do not hold a use. Every other state does. */
    private const RELEASED = ['cancelled'];

    public function __construct(private readonly Database $db) {}

    public function find(int $id, bool $lock = false): ?Promotion
    {
        $row = $this->db->fetchAssociative('SELECT * FROM promotions WHERE id=:id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
        return $row === false ? null : Promotion::fromRow($row, $this->courseIds($id), $this->bundleIds($id));
    }

    public function findByCode(string $canonicalCode): ?Promotion
    {
        $row = $this->db->fetchAssociative('SELECT * FROM promotions WHERE code=:code', ['code' => $canonicalCode]);
        return $row === false ? null : Promotion::fromRow($row, $this->courseIds((int) $row['id']), $this->bundleIds((int) $row['id']));
    }

    public function codeTaken(string $canonicalCode, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM promotions WHERE code=:code AND id IS DISTINCT FROM :id', ['code' => $canonicalCode, 'id' => $exceptId]);
    }

    /** @return list<int> */
    public function courseIds(int $promotionId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT course_id FROM promotion_courses WHERE promotion_id=:id ORDER BY course_id', ['id' => $promotionId]));
    }

    /** @return list<int> */
    public function bundleIds(int $promotionId): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT bundle_id FROM promotion_bundles WHERE promotion_id=:id ORDER BY bundle_id', ['id' => $promotionId]));
    }

    /** @return list<array{id:int,label:string,detail:string}> */
    public function selectedBundles(int $promotionId): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'detail' => (string) $row['detail']],
            $this->db->fetchAllAssociative('SELECT b.id, b.title AS label, b.slug AS detail FROM promotion_bundles pb JOIN bundles b ON b.id=pb.bundle_id WHERE pb.promotion_id=:id ORDER BY b.title, b.id', ['id' => $promotionId]));
    }

    /**
     * @param list<int> $bundleIds
     * @return list<array{id:int,label:string,detail:string}>
     */
    public function bundlesById(array $bundleIds): array
    {
        if ($bundleIds === []) return [];
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'detail' => (string) $row['detail']],
            $this->db->fetchAllAssociative('SELECT id, title AS label, slug AS detail FROM bundles WHERE id = ANY(CAST(:ids AS BIGINT[])) ORDER BY title, id', ['ids' => '{' . implode(',', array_map('intval', $bundleIds)) . '}']));
    }

    /** @return list<array{id:int,label:string,detail:string}> */
    public function selectedCourses(int $promotionId): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'detail' => (string) $row['detail']],
            $this->db->fetchAllAssociative('SELECT c.id, c.title AS label, c.slug AS detail FROM promotion_courses pc JOIN courses c ON c.id=pc.course_id WHERE pc.promotion_id=:id ORDER BY c.title, c.id', ['id' => $promotionId]));
    }

    /**
     * @param list<int> $courseIds
     * @return list<array{id:int,label:string,detail:string}>
     */
    public function coursesById(array $courseIds): array
    {
        if ($courseIds === []) return [];
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'], 'detail' => (string) $row['detail']],
            $this->db->fetchAllAssociative('SELECT id, title AS label, slug AS detail FROM courses WHERE id = ANY(CAST(:ids AS BIGINT[])) ORDER BY title, id', ['ids' => '{' . implode(',', array_map('intval', $courseIds)) . '}']));
    }

    /**
     * Uses held against the limits: all of them and the given purchaser's. Read under the
     * promotion's row lock at placement, so two placements cannot both take the last use.
     *
     * @return array{total:int,customer:int}
     */
    public function usage(int $promotionId, int $userId): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE purchaser_user_id=:user) AS customer FROM commerce_orders WHERE promotion_id=:id AND state <> ALL(CAST(:released AS TEXT[]))',
            ['id' => $promotionId, 'user' => $userId, 'released' => '{' . implode(',', self::RELEASED) . '}']
        ) ?: [];
        return ['total' => (int) ($row['total'] ?? 0), 'customer' => (int) ($row['customer'] ?? 0)];
    }

    /** Whether any order, in any state, was placed with the promotion. Such a promotion is kept. */
    public function used(int $promotionId): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM commerce_orders WHERE promotion_id=:id LIMIT 1', ['id' => $promotionId]);
    }

    /**
     * @param array<string,mixed> $fields promotions columns
     * @param list<int> $courseIds
     * @param list<int> $bundleIds
     */
    public function create(array $fields, array $courseIds, int $actorId, string $now, array $bundleIds = []): int
    {
        $id = (int) $this->db->fetchOne(
            'INSERT INTO promotions(code,name,description,discount_type,discount_value,currency,starts_at,ends_at,active,minimum_order_minor,maximum_total_uses,maximum_uses_per_customer,course_scope,bundle_scope,created_at,updated_at,created_by,updated_by)
             VALUES (:code,:name,:description,:discount_type,:discount_value,:currency,:starts_at,:ends_at,:active,:minimum_order_minor,:maximum_total_uses,:maximum_uses_per_customer,:course_scope,:bundle_scope,:now,:now,:actor,:actor) RETURNING id',
            self::bind($fields) + ['now' => $now, 'actor' => $actorId]
        );
        $this->replaceSelection($id, $courseIds, $bundleIds);
        return $id;
    }

    /**
     * @param array<string,mixed> $fields promotions columns
     * @param list<int> $courseIds
     * @param list<int> $bundleIds
     */
    public function update(int $id, array $fields, array $courseIds, int $actorId, string $now, array $bundleIds = []): void
    {
        $this->db->executeStatement(
            'UPDATE promotions SET code=:code,name=:name,description=:description,discount_type=:discount_type,discount_value=:discount_value,currency=:currency,
                starts_at=:starts_at,ends_at=:ends_at,active=:active,minimum_order_minor=:minimum_order_minor,maximum_total_uses=:maximum_total_uses,
                maximum_uses_per_customer=:maximum_uses_per_customer,course_scope=:course_scope,bundle_scope=:bundle_scope,updated_at=:now,updated_by=:actor WHERE id=:id',
            self::bind($fields) + ['now' => $now, 'actor' => $actorId, 'id' => $id]
        );
        $this->replaceSelection($id, $courseIds, $bundleIds);
    }

    public function setActive(int $id, bool $active, int $actorId, string $now): bool
    {
        return $this->db->executeStatement('UPDATE promotions SET active=:active, updated_at=:now, updated_by=:actor WHERE id=:id AND active IS DISTINCT FROM :active',
            ['id' => $id, 'active' => $active ? 't' : 'f', 'now' => $now, 'actor' => $actorId]) > 0;
    }

    public function delete(int $id): void
    {
        $this->db->executeStatement('DELETE FROM promotions WHERE id=:id', ['id' => $id]);
    }

    /** Records a paid order's redemption once; false when it was already recorded. */
    public function recordRedemption(int $promotionId, int $orderId, int $purchaserId, string $code, int $discountMinor, string $currency, string $now): bool
    {
        return $this->db->fetchOne(
            'INSERT INTO promotion_redemptions(promotion_id,order_id,purchaser_user_id,code,discount_minor,currency,redeemed_at) VALUES (:promotion,:order,:user,:code,:discount,:currency,:now) ON CONFLICT (order_id) DO NOTHING RETURNING id',
            ['promotion' => $promotionId, 'order' => $orderId, 'user' => $purchaserId, 'code' => $code, 'discount' => $discountMinor, 'currency' => $currency, 'now' => $now]
        ) !== false;
    }

    /**
     * @param array{search?:string,status?:string} $filters
     */
    public function count(array $filters, string $now): int
    {
        [$where, $params] = self::where($filters, $now);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM promotions p' . $where, $params);
    }

    /**
     * The ADMIN list: each promotion with its redemptions, the uses its unpaid orders hold, and its
     * number of selected courses.
     *
     * @param array{search?:string,status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, string $now, int $limit, int $offset): array
    {
        [$where, $params] = self::where($filters, $now);
        return $this->db->fetchAllAssociative(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM promotion_redemptions r WHERE r.promotion_id=p.id) AS redeemed,
                    (SELECT COUNT(*) FROM commerce_orders o WHERE o.promotion_id=p.id AND o.state <> \'cancelled\'
                        AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.order_id=o.id)) AS pending,
                    (SELECT COUNT(*) FROM promotion_courses pc WHERE pc.promotion_id=p.id) AS course_count,
                    (SELECT COUNT(*) FROM promotion_bundles pb WHERE pb.promotion_id=p.id) AS bundle_count
               FROM promotions p' . $where . ' ORDER BY p.id DESC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /** @return array{redeemed:int,pending:int,discount_minor:int} */
    public function usageSummary(int $promotionId): array
    {
        $row = $this->db->fetchAssociative(
            "SELECT (SELECT COUNT(*) FROM promotion_redemptions WHERE promotion_id=:id) AS redeemed,
                    (SELECT COALESCE(SUM(discount_minor),0) FROM promotion_redemptions WHERE promotion_id=:id) AS discount_minor,
                    (SELECT COUNT(*) FROM commerce_orders o WHERE o.promotion_id=:id AND o.state <> 'cancelled'
                        AND NOT EXISTS (SELECT 1 FROM promotion_redemptions r WHERE r.order_id=o.id)) AS pending",
            ['id' => $promotionId]
        ) ?: [];
        return ['redeemed' => (int) ($row['redeemed'] ?? 0), 'pending' => (int) ($row['pending'] ?? 0), 'discount_minor' => (int) ($row['discount_minor'] ?? 0)];
    }

    public function orderCount(int $promotionId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE promotion_id=:id', ['id' => $promotionId]);
    }

    /**
     * Every order placed with the promotion, newest first, with its redemption when paid.
     *
     * @return list<array<string,mixed>>
     */
    public function orders(int $promotionId, int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT o.id, o.state, o.total_minor, o.discount_minor, o.currency, o.placed_at, u.display_name AS purchaser_name, r.redeemed_at, r.code AS redeemed_code
               FROM commerce_orders o JOIN users u ON u.id=o.purchaser_user_id LEFT JOIN promotion_redemptions r ON r.order_id=o.id
              WHERE o.promotion_id=:id ORDER BY o.id DESC LIMIT :limit OFFSET :offset',
            ['id' => $promotionId, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * @param list<int> $courseIds
     * @param list<int> $bundleIds
     */
    private function replaceSelection(int $id, array $courseIds, array $bundleIds): void
    {
        $this->db->executeStatement('DELETE FROM promotion_courses WHERE promotion_id=:id', ['id' => $id]);
        foreach (array_values(array_unique($courseIds)) as $courseId) {
            $this->db->executeStatement('INSERT INTO promotion_courses(promotion_id,course_id) VALUES (:id,:course)', ['id' => $id, 'course' => $courseId]);
        }
        $this->db->executeStatement('DELETE FROM promotion_bundles WHERE promotion_id=:id', ['id' => $id]);
        foreach (array_values(array_unique($bundleIds)) as $bundleId) {
            $this->db->executeStatement('INSERT INTO promotion_bundles(promotion_id,bundle_id) VALUES (:id,:bundle)', ['id' => $id, 'bundle' => $bundleId]);
        }
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function bind(array $fields): array
    {
        $bound = [];
        foreach (['code','name','description','discount_type','discount_value','currency','starts_at','ends_at','minimum_order_minor','maximum_total_uses','maximum_uses_per_customer','course_scope','bundle_scope'] as $key) {
            $bound[$key] = $fields[$key] ?? null;
        }
        $bound['active'] = ($fields['active'] ?? false) ? 't' : 'f';
        return $bound;
    }

    /**
     * @param array{search?:string,status?:string} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function where(array $filters, string $now): array
    {
        $clauses = [];
        $params = [];
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $clauses[] = '(p.code ILIKE :search OR p.name ILIKE :search)';
            $params['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $status = (string) ($filters['status'] ?? '');
        $open = '(p.starts_at IS NULL OR p.starts_at <= :now) AND (p.ends_at IS NULL OR p.ends_at > :now)';
        $condition = match ($status) {
            'active' => 'p.active AND ' . $open,
            'scheduled' => 'p.active AND p.starts_at > :now AND (p.ends_at IS NULL OR p.ends_at > :now)',
            'ended' => 'p.ends_at <= :now',
            'inactive' => 'NOT p.active AND (p.ends_at IS NULL OR p.ends_at > :now)',
            default => null,
        };
        if ($condition !== null) {
            $clauses[] = $condition;
            $params['now'] = $now;
        }
        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $params];
    }
}
