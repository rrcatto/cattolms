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
    private const WITH_OFFER = 'SELECT b.*, o.id AS offer_id, o.price_minor_units, o.was_price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active
        FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id = b.id';
    /** The order bundles are listed in (owner, 2026-11-12): those with a position first, lowest first, then the newest. */
    public const LISTED_ORDER = 'b.catalogue_position ASC NULLS LAST, b.published_at DESC NULLS LAST, b.title, b.id';

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

    /**
     * A bundle's own drawing, for its card and its page (owner, 2026-11-07, the approved mockup): null for a draft, an
     * unknown address or a bundle without one. A retired bundle's stays, as its page does.
     */
    public function publicCover(string $slug): ?string
    {
        $cover = $this->db->fetchOne("SELECT cover_svg FROM bundles WHERE slug = :slug AND status IN ('published', 'retired') AND cover_svg LIKE '<svg%'", ['slug' => $slug]);
        return is_string($cover) ? $cover : null;
    }

    public function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM bundles WHERE slug = :slug AND id IS DISTINCT FROM :id', ['slug' => $slug, 'id' => $exceptId]);
    }

    /**
     * The bundle with this code (owner, 2026-11-06), the one thing a catalogue import matches a bundle by.
     *
     * @return array<string,mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->db->fetchAssociative(self::WITH_OFFER . ' WHERE b.code = :code', ['code' => $code]) ?: null;
    }

    /** Whether another bundle has this code. */
    public function codeTaken(string $code, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM bundles WHERE code = :code AND id IS DISTINCT FROM :id', ['code' => $code, 'id' => $exceptId]);
    }

    /**
     * The course with this code, if a course has it: a code names one course or one bundle, never both.
     *
     * @return array{id:int,title:string}|null
     */
    public function courseWithCode(string $code): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, title FROM courses WHERE code = :code', ['code' => $code]);
        return $row === false ? null : ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }

    /** @param array<string,mixed> $fields bundles columns */
    public function create(array $fields, int $actorId, string $now): int
    {
        return (int) $this->db->fetchOne(
            'INSERT INTO bundles(public_id,code,slug,title,short_description,description_html,cover_svg,available_from,available_until,created_at,updated_at,created_by_user_id,updated_by_user_id)
             VALUES (:public,:code,:slug,:title,:short,:description,:cover,:from,:until,:now,:now,:actor,:actor) RETURNING id',
            self::bind($fields) + ['public' => Uuid::v4(), 'now' => $now, 'actor' => $actorId]
        );
    }

    /** @param array<string,mixed> $fields bundles columns */
    public function update(int $id, array $fields, int $actorId, string $now): void
    {
        $this->db->executeStatement(
            'UPDATE bundles SET code=:code,slug=:slug,title=:title,short_description=:short,description_html=:description,cover_svg=:cover,available_from=:from,available_until=:until,
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
            "SELECT bc.position, c.id AS course_id, c.code AS course_code, c.title, c.slug, c.status,
                    v.price_minor_units AS default_price_minor_units, v.currency_code AS default_currency_code
               FROM bundle_courses bc
               JOIN courses c ON c.id = bc.course_id
               LEFT JOIN course_prices_on_sale v ON v.course_id = c.id AND v.is_default
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
    public function saveOffer(int $bundleId, int $priceMinor, string $currency, int $periodSeconds, bool $active, int $actorId, string $now, ?int $wasMinor = null): void
    {
        $this->db->executeStatement(
            'INSERT INTO bundle_offers(public_id,bundle_id,price_minor_units,was_price_minor_units,currency_code,access_period_seconds,is_active,created_at,updated_at,created_by_user_id,updated_by_user_id)
             VALUES (:public,:bundle,:price,:was,:currency,:period,:active,:now,:now,:actor,:actor)
             ON CONFLICT (bundle_id) DO UPDATE SET price_minor_units=EXCLUDED.price_minor_units, was_price_minor_units=EXCLUDED.was_price_minor_units, currency_code=EXCLUDED.currency_code,
                access_period_seconds=EXCLUDED.access_period_seconds, is_active=EXCLUDED.is_active, updated_at=EXCLUDED.updated_at, updated_by_user_id=EXCLUDED.updated_by_user_id',
            ['public' => Uuid::v4(), 'bundle' => $bundleId, 'price' => $priceMinor, 'was' => $wasMinor, 'currency' => $currency, 'period' => $periodSeconds, 'active' => $active ? 't' : 'f', 'now' => $now, 'actor' => $actorId]
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
            'SELECT o.id AS order_id, o.state, o.placed_at, o.currency, i.id AS item_id, i.amount_minor, i.discount_minor, u.display_name AS purchaser_name,
                    o.purchaser_user_id, u.account_reference AS purchaser_reference, ue.email AS purchaser_email
               FROM commerce_order_items i JOIN commerce_orders o ON o.id=i.order_id JOIN users u ON u.id=o.purchaser_user_id LEFT JOIN user_emails ue ON ue.user_id=u.id AND ue.is_primary
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

    /** What the ADMIN list may be sorted by (owner, 2026-11-06: its columns), each made total by the id. */
    public const LIST_SORTS = [
        'code' => 'b.code', 'title' => 'b.title', 'status' => 'b.status', 'price' => 'o.price_minor_units', 'from' => 'b.available_from',
        'until' => 'b.available_until', 'access' => 'o.access_period_seconds', 'courses' => 'course_count', 'sales' => 'sales', 'updated' => 'b.updated_at',
        'position' => 'b.catalogue_position',
    ];

    /**
     * The ADMIN list: each bundle with its offer, number of courses and number of sales, in the order asked for (the most
     * recently changed first when none is).
     *
     * @param array{search?:string,status?:string} $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters, int $limit, int $offset, ?\CattoLearning\Support\SortOrder $sort = null): array
    {
        [$where, $params] = self::where($filters);
        $order = match (true) {
            $sort === null => 'b.updated_at DESC, b.id DESC',
            // In their listed order, as the public pages show them, and the other way round.
            $sort->key === 'position' => $sort->direction === \CattoLearning\Support\SortOrder::ASCENDING ? self::LISTED_ORDER : 'b.catalogue_position DESC NULLS FIRST, b.published_at ASC NULLS FIRST, b.title DESC, b.id DESC',
            default => (self::LIST_SORTS[$sort->key] ?? 'b.updated_at') . ' ' . $sort->sql() . ' NULLS LAST, b.id ' . $sort->sql(),
        };
        return $this->db->fetchAllAssociative(
            'SELECT b.*, o.id AS offer_id, o.price_minor_units, o.was_price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
                    (SELECT COUNT(*) FROM bundle_courses bc WHERE bc.bundle_id=b.id) AS course_count,
                    (SELECT COUNT(*) FROM commerce_order_items i WHERE i.bundle_id=b.id) AS sales
               FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id' . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Every bundle with its code and position in the listed order, locked, for a move or Renumber (BundlePositions).
     *
     * @return list<array{id:int,code:string,position:?int}>
     */
    public function orderForUpdate(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'position' => $row['catalogue_position'] === null ? null : (int) $row['catalogue_position']],
            $this->db->fetchAllAssociative('SELECT b.id, b.code, b.catalogue_position FROM bundles b ORDER BY ' . self::LISTED_ORDER . ' FOR UPDATE'));
    }

    /** @return list<int> every position bundles have now, for the next free number in a band */
    public function positions(): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT catalogue_position FROM bundles WHERE catalogue_position IS NOT NULL'));
    }

    /** The code of the bundle listed just before this one (empty for the first), which a dragged first row goes after. */
    public function codeBefore(int $id): string
    {
        $previous = '';
        foreach ($this->db->fetchAllAssociative('SELECT b.id, b.code FROM bundles b ORDER BY ' . self::LISTED_ORDER) as $row) {
            if ((int) $row['id'] === $id) return $previous;
            $previous = (string) $row['code'];
        }
        return '';
    }

    /** A bundle's position; $changed says whether its date of change moves with it (a move or a typed number), which Renumber leaves alone. */
    public function setPosition(int $id, ?int $position, bool $changed = true): void
    {
        $this->db->executeStatement('UPDATE bundles SET catalogue_position = :position' . ($changed ? ', updated_at = NOW()' : '') . ' WHERE id = :id', ['position' => $position, 'id' => $id]);
    }

    /**
     * One bundle as the ADMIN list shows it (list()), for its row or card drawn again after it is edited in place.
     *
     * @return array<string,mixed>|null
     */
    public function listRow(int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT b.*, o.id AS offer_id, o.price_minor_units, o.was_price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
                    (SELECT COUNT(*) FROM bundle_courses bc WHERE bc.bundle_id=b.id) AS course_count,
                    (SELECT COUNT(*) FROM commerce_order_items i WHERE i.bundle_id=b.id) AS sales
               FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id WHERE b.id=:id',
            ['id' => $id]
        ) ?: null;
    }

    /**
     * Which of these bundles the person starred (owner, 2026-11-06).
     *
     * @param list<int> $bundleIds
     * @return list<int>
     */
    public function favouriteIds(int $userId, array $bundleIds): array
    {
        $bundleIds = array_values(array_unique(array_filter(array_map('intval', $bundleIds), static fn(int $id): bool => $id > 0)));
        if ($bundleIds === []) return [];
        return array_map('intval', $this->db->fetchFirstColumn(
            'SELECT bundle_id FROM bundle_favourites WHERE user_id=:user AND bundle_id = ANY(CAST(:ids AS BIGINT[]))',
            ['user' => $userId, 'ids' => '{' . implode(',', $bundleIds) . '}']
        ));
    }

    /** Stars or unstars a bundle for a person, and says whether that changed anything (a repeated request changes nothing). */
    public function setFavourite(int $userId, int $bundleId, bool $favourite): bool
    {
        $rows = $favourite
            ? $this->db->fetchAllAssociative('INSERT INTO bundle_favourites (user_id,bundle_id,created_at) VALUES (:user,:bundle,NOW()) ON CONFLICT (user_id,bundle_id) DO NOTHING RETURNING bundle_id', ['user' => $userId, 'bundle' => $bundleId])
            : $this->db->fetchAllAssociative('DELETE FROM bundle_favourites WHERE user_id=:user AND bundle_id=:bundle RETURNING bundle_id', ['user' => $userId, 'bundle' => $bundleId]);
        return $rows !== [];
    }

    /**
     * Every bundle with its offer and its courses' codes in order, for a catalogue package (owner, 2026-11-06: bundles
     * "backed up as part of the catalogue").
     *
     * @return list<array<string,mixed>> each with course_codes (list<string>) and course_ids (list<int>)
     */
    public function allWithCourseCodes(): array
    {
        $rows = $this->db->fetchAllAssociative(self::WITH_OFFER . ' ORDER BY b.code, b.id');
        $courses = [];
        foreach ($this->db->fetchAllAssociative('SELECT bc.bundle_id, c.id, c.code FROM bundle_courses bc JOIN courses c ON c.id = bc.course_id ORDER BY bc.bundle_id, bc.position') as $row) {
            $courses[(int) $row['bundle_id']][] = ['id' => (int) $row['id'], 'code' => (string) $row['code']];
        }
        return array_map(static fn(array $row): array => $row + ['course_codes' => array_column($courses[(int) $row['id']] ?? [], 'code'), 'course_ids' => array_column($courses[(int) $row['id']] ?? [], 'id')], $rows);
    }

    /**
     * Puts a bundle's courses in this order, replacing the ones it had (a catalogue import).
     *
     * @param list<int> $courseIds
     */
    public function replaceCourses(int $bundleId, array $courseIds): void
    {
        $this->db->executeStatement('DELETE FROM bundle_courses WHERE bundle_id=:b', ['b' => $bundleId]);
        foreach ($courseIds as $index => $courseId) {
            $this->db->executeStatement('INSERT INTO bundle_courses(bundle_id,course_id,position) VALUES (:b,:c,:p)', ['b' => $bundleId, 'c' => (int) $courseId, 'p' => $index + 1]);
        }
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
            "SELECT b.*, o.id AS offer_id, o.price_minor_units, o.was_price_minor_units, o.currency_code, o.access_period_seconds, o.is_active AS offer_active,
                    (SELECT COUNT(*) FROM bundle_courses bc WHERE bc.bundle_id=b.id) AS course_count
               FROM bundles b LEFT JOIN bundle_offers o ON o.bundle_id=b.id
              WHERE b.status='published' ORDER BY " . self::LISTED_ORDER . ' LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Published bundles with their offer, for the bundles shown beside the courses (owner, 2026-11-06, the
     * approved mockup): those holding this course (a course page), those holding a course filed in this
     * category's branch (a category's page, the branch as the catalogue counts it), or every one (All
     * courses). In the Bundles page's order; whether each can be bought now is BundleRules' to say.
     *
     * @param array{course?:int,category?:int} $holding
     * @return list<array<string,mixed>>
     */
    public function publishedFor(array $holding = []): array
    {
        $where = "b.status='published'";
        $params = [];
        if (isset($holding['course'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM bundle_courses bc WHERE bc.bundle_id=b.id AND bc.course_id=:course)';
            $params['course'] = $holding['course'];
        } elseif (isset($holding['category'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM bundle_courses bc JOIN courses c ON c.id=bc.course_id WHERE bc.bundle_id=b.id AND '
                . \CattoLearning\Course\CourseRepository::inBranchOf('c', ':category') . ')';
            $params['category'] = $holding['category'];
        }
        return $this->db->fetchAllAssociative(self::WITH_OFFER . ' WHERE ' . $where . ' ORDER BY ' . self::LISTED_ORDER, $params);
    }

    /**
     * Which of these courses have a cover anyone may see, each with a version of it for its address: a published course's
     * drawn cover, served at /courses/{slug}/cover.svg (a bundle's card shows its courses' covers, the approved mockup of
     * 2026-11-07). A course without one is left out.
     *
     * @param list<int> $courseIds
     * @return array<int,string> course id => version
     */
    public function coverVersions(array $courseIds): array
    {
        if ($courseIds === []) return [];
        $versions = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT id, substr(md5(cover_svg), 1, 12) AS version FROM courses
              WHERE id = ANY(CAST(:ids AS BIGINT[])) AND status = 'published' AND cover_svg LIKE '<svg%'",
            ['ids' => '{' . implode(',', array_map('intval', $courseIds)) . '}']
        ) as $row) $versions[(int) $row['id']] = (string) $row['version'];
        return $versions;
    }

    /**
     * The courses of several bundles in one read, each bundle's in its order, as courses() gives them.
     *
     * @param list<int> $bundleIds
     * @return array<int,list<array<string,mixed>>> keyed by bundle id
     */
    public function coursesOf(array $bundleIds): array
    {
        if ($bundleIds === []) return [];
        $grouped = array_fill_keys(array_map('intval', $bundleIds), []);
        foreach ($this->db->fetchAllAssociative(
            "SELECT bc.bundle_id, bc.position, c.id AS course_id, c.code AS course_code, c.title, c.slug, c.status,
                    v.price_minor_units AS default_price_minor_units, v.currency_code AS default_currency_code
               FROM bundle_courses bc
               JOIN courses c ON c.id = bc.course_id
               LEFT JOIN course_prices_on_sale v ON v.course_id = c.id AND v.is_default
              WHERE bc.bundle_id = ANY(CAST(:ids AS BIGINT[])) ORDER BY bc.bundle_id, bc.position",
            ['ids' => '{' . implode(',', array_map('intval', $bundleIds)) . '}']
        ) as $row) {
            $bundleId = (int) $row['bundle_id'];
            unset($row['bundle_id']);
            $grouped[$bundleId][] = $row;
        }
        return $grouped;
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
        // A bundle made without a code gets a random one, as the column's own default does (BundleService gives a suggestion).
        return ['code' => (string) ($fields['code'] ?? '') !== '' ? (string) $fields['code'] : 'BDL-' . strtoupper(bin2hex(random_bytes(4))), 'slug' => $fields['slug'], 'title' => $fields['title'], 'short' => $fields['short_description'], 'description' => $fields['description_html'],
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
            $clauses[] = '(b.title ILIKE :search OR b.slug ILIKE :search OR b.code ILIKE :search)';
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
