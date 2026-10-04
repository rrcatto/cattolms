<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\SortOrder;
use DateTimeImmutable;

/**
 * The popularity snapshot: which courses take part, writing a calculated ranking, and the query API
 * later consumers use (popularCourses() for the home page, ranked() for ADMIN) without knowing how
 * popularity is calculated. Readers always filter on the course's current status as well, so a
 * course unpublished since the last calculation is never promoted.
 */
final class CoursePopularityRepository
{
    private const TIME = 'Y-m-d H:i:sP';
    /** Rows per INSERT when writing a snapshot. */
    private const CHUNK = 200;
    /** Serialises recalculations; any constant unique to this lock. */
    private const LOCK_KEY = 7420261004;

    /** ADMIN sort keys and their SQL. Every order ends in the course id. */
    public const SORTS = [
        'rank' => 'p.rank', 'course' => 'c.title', 'score' => 'p.score', 'views' => 'p.unique_views', 'favourites' => 'p.favourites',
        'purchases' => 'p.purchases', 'refunds' => 'p.refunded_units', 'rating' => 'p.weighted_rating', 'reviews' => 'p.approved_reviews', 'momentum' => 'p.momentum_growth',
    ];

    private const SELECT = "SELECT c.id AS course_id, c.slug, c.title, c.status, p.rank, p.score,
                p.view_points, p.favourite_points, p.purchase_points, p.refund_penalty_points, p.rating_points, p.momentum_points,
                p.offer, p.unique_views, p.learner_readers, p.favourites, p.purchases, p.purchased_units, p.refunds, p.refunded_units,
                p.refund_ratio, p.approved_reviews, p.average_rating, p.weighted_rating, p.recent_views, p.previous_views, p.momentum_growth,
                r.calculated_at, r.window_start, r.window_end
           FROM course_popularity p
           JOIN course_popularity_runs r ON r.id = p.run_id
           JOIN courses c ON c.id = p.course_id";

    public function __construct(private readonly Database $db, private readonly TransactionManager $transactions)
    {
    }

    /**
     * The courses that take part in popularity: published courses, the ones the public catalogue
     * shows. Drafts (including courses only granted to testers), retired and archived courses are
     * left out however much history they have. Each comes with its offer: paid when an active price
     * variant has a price, free when every active variant is free, none without an active variant.
     *
     * @return array<int,'paid'|'free'|'none'> keyed by course id
     */
    public function eligibleCourses(): array
    {
        $courses = [];
        foreach ($this->db->fetchAllAssociative(
            "SELECT c.id,
                    CASE WHEN bool_or(v.price_minor_units > 0) THEN 'paid' WHEN bool_or(v.id IS NOT NULL) THEN 'free' ELSE 'none' END AS offer
               FROM courses c
               LEFT JOIN course_price_variants v ON v.course_id = c.id AND v.is_active
              WHERE c.status = 'published'
              GROUP BY c.id ORDER BY c.id"
        ) as $row) {
            $offer = (string) $row['offer'];
            $courses[(int) $row['id']] = $offer === 'paid' ? 'paid' : ($offer === 'free' ? 'free' : 'none');
        }
        return $courses;
    }

    /**
     * Replaces the current snapshot with a new run in one transaction: concurrent recalculations
     * wait for each other, and readers see either the old ranking or the new one, never a mix.
     *
     * @param array<string,mixed> $parameters
     * @param list<CoursePopularityScore> $scores
     * @return int the new run's id
     */
    public function replace(DateTimeImmutable $calculatedAt, DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, array $parameters, array $scores): int
    {
        return $this->transactions->run(function () use ($calculatedAt, $windowStart, $windowEnd, $parameters, $scores): int {
            $this->db->fetchOne('SELECT pg_advisory_xact_lock(' . self::LOCK_KEY . ')');
            // Removing the old runs removes their rows with them.
            $this->db->executeStatement('DELETE FROM course_popularity_runs');
            $runId = (int) $this->db->fetchOne(
                'INSERT INTO course_popularity_runs (calculated_at, window_start, window_end, parameters, course_count)
                 VALUES (:at, :start, :end, CAST(:parameters AS JSONB), :count) RETURNING id',
                ['at' => $calculatedAt->format(self::TIME), 'start' => $windowStart->format(self::TIME), 'end' => $windowEnd->format(self::TIME),
                    'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR), 'count' => count($scores)]
            );
            foreach (array_chunk($scores, self::CHUNK) as $chunk) {
                $values = []; $bindings = ['run' => $runId];
                foreach ($chunk as $i => $score) {
                    $m = $score->metrics;
                    $row = [
                        'course' => $m->courseId, 'rank' => $score->rank, 'score' => $score->score,
                        'view' => $score->viewPoints, 'favourite' => $score->favouritePoints, 'purchase' => $score->purchasePoints,
                        'penalty' => $score->refundPenaltyPoints, 'rating' => $score->ratingPoints, 'momentum' => $score->momentumPoints,
                        'offer' => $m->offer, 'views' => $m->uniqueViews, 'readers' => $m->learnerReaders, 'favourites' => $m->favourites,
                        'purchases' => $m->purchases, 'units' => $m->purchasedUnits, 'refunds' => $m->refunds, 'refunded' => round($m->refundedUnits, 4),
                        'ratio' => round($score->refundRatio, 4), 'reviews' => $m->rating->count,
                        'average' => $m->rating->average === null ? null : round($m->rating->average, 3),
                        'weighted' => $score->weightedRating === null ? null : round($score->weightedRating, 3),
                        'recent' => $m->recentViews, 'previous' => $m->previousViews, 'growth' => round($score->momentumGrowth, 4),
                    ];
                    $placeholders = [];
                    foreach ($row as $key => $value) { $bindings[$key . $i] = $value; $placeholders[] = ':' . $key . $i; }
                    $values[] = '(:run, ' . implode(', ', $placeholders) . ')';
                }
                $this->db->executeStatement(
                    'INSERT INTO course_popularity (run_id, course_id, rank, score, view_points, favourite_points, purchase_points, refund_penalty_points,
                        rating_points, momentum_points, offer, unique_views, learner_readers, favourites, purchases, purchased_units, refunds, refunded_units,
                        refund_ratio, approved_reviews, average_rating, weighted_rating, recent_views, previous_views, momentum_growth)
                     VALUES ' . implode(', ', $values),
                    $bindings
                );
            }
            return $runId;
        });
    }

    /**
     * The current run: when it was calculated, its window and the model parameters it used.
     *
     * @return array{id:int,calculated_at:string,window_start:string,window_end:string,course_count:int,parameters:array<string,mixed>}|null
     */
    public function currentRun(): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, calculated_at, window_start, window_end, course_count, parameters::text AS parameters FROM course_popularity_runs ORDER BY id DESC LIMIT 1');
        if ($row === false) { return null; }
        $parameters = json_decode((string) $row['parameters'], true);
        return ['id' => (int) $row['id'], 'calculated_at' => (string) $row['calculated_at'], 'window_start' => (string) $row['window_start'],
            'window_end' => (string) $row['window_end'], 'course_count' => (int) $row['course_count'], 'parameters' => is_array($parameters) ? $parameters : []];
    }

    /**
     * The most popular courses, best first: published now, with a score above zero (a course with
     * no signal at all is not popular), optionally only those with an active price variant.
     *
     * @return list<array<string,mixed>>
     */
    public function popularCourses(int $limit, bool $saleableOnly = false): array
    {
        return array_map(self::row(...), $this->db->fetchAllAssociative(
            self::SELECT . " WHERE c.status = 'published' AND p.score > 0" . ($saleableOnly ? self::SALEABLE : '') . '
             ORDER BY p.score DESC, p.course_id LIMIT :limit',
            ['limit' => max(1, min(100, $limit))]
        ));
    }

    /**
     * One course's popularity, whatever its score, while it is published.
     *
     * @return array<string,mixed>|null
     */
    public function forCourse(int $courseId): ?array
    {
        $row = $this->db->fetchAssociative(self::SELECT . " WHERE c.status = 'published' AND p.course_id = :course", ['course' => $courseId]);
        return $row === false ? null : self::row($row);
    }

    /**
     * One page of the ranking for ADMIN, with every metric and component.
     *
     * @param ''|'paid'|'free'|'none' $offer
     * @return list<array<string,mixed>>
     */
    public function ranked(string $search, string $offer, bool $saleableOnly, SortOrder $sort, int $limit, int $offset): array
    {
        [$where, $bindings] = self::filter($search, $offer, $saleableOnly);
        $column = self::SORTS[$sort->key] ?? self::SORTS['rank'];
        $direction = $sort->isDescending() ? 'DESC' : 'ASC';
        return array_map(self::row(...), $this->db->fetchAllAssociative(
            self::SELECT . $where . " ORDER BY {$column} {$direction} NULLS LAST, p.course_id LIMIT :limit OFFSET :offset",
            $bindings + ['limit' => max(1, $limit), 'offset' => max(0, $offset)]
        ));
    }

    /** @param ''|'paid'|'free'|'none' $offer */
    public function rankedCount(string $search, string $offer, bool $saleableOnly): int
    {
        [$where, $bindings] = self::filter($search, $offer, $saleableOnly);
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_popularity p JOIN courses c ON c.id = p.course_id' . $where, $bindings);
    }

    private const SALEABLE = ' AND EXISTS (SELECT 1 FROM course_price_variants v WHERE v.course_id = c.id AND v.is_active)';

    /** @return array{0:string,1:array<string,mixed>} */
    private static function filter(string $search, string $offer, bool $saleableOnly): array
    {
        $where = " WHERE c.status = 'published'"; $bindings = [];
        if (trim($search) !== '') { $where .= ' AND c.title ILIKE :search'; $bindings['search'] = '%' . addcslashes(trim($search), '%_\\') . '%'; }
        if (in_array($offer, CoursePopularityMetrics::OFFERS, true)) { $where .= ' AND p.offer = :offer'; $bindings['offer'] = $offer; }
        if ($saleableOnly) { $where .= self::SALEABLE; }
        return [$where, $bindings];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function row(array $row): array
    {
        foreach (['course_id', 'rank', 'unique_views', 'learner_readers', 'favourites', 'purchases', 'purchased_units', 'refunds', 'approved_reviews', 'recent_views', 'previous_views'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        foreach (['score', 'view_points', 'favourite_points', 'purchase_points', 'refund_penalty_points', 'rating_points', 'momentum_points', 'refunded_units', 'refund_ratio', 'momentum_growth'] as $key) {
            $row[$key] = (float) $row[$key];
        }
        foreach (['average_rating', 'weighted_rating'] as $key) {
            $row[$key] = $row[$key] === null ? null : (float) $row[$key];
        }
        return $row;
    }
}
