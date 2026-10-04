<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Infrastructure\Persistence\Database;
use DateTimeImmutable;

/**
 * Course reviews: one row per learner per course. The row's rating, comment and status are the
 * learner's current submission, which ADMIN moderates; published_rating, published_comment and
 * published_at are the last version ADMIN approved, and they are the only part of a review that is
 * ever shown publicly or counted in a course's rating.
 */
final class CourseReviewRepository
{
    private const TIME = 'Y-m-d H:i:sP';

    /**
     * The name a review is published under: first name and the initial of the surname, or the first
     * word of the display name. Never an email address.
     */
    private const PUBLIC_NAME = "COALESCE(NULLIF(btrim(u.first_name),'') || COALESCE(' ' || left(NULLIF(btrim(u.last_name),''),1) || '.', ''),
                                          NULLIF(split_part(btrim(COALESCE(u.display_name,'')),' ',1),''), 'Learner')";

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * A learner may review a course they hold, or held, a genuine entitlement to: any learner
     * enrolment that was assigned, is active or completed, or has expired. A cancelled enrolment
     * (refunded or access removed) and an ADMIN preview enrolment never qualify.
     */
    public function hasReviewEntitlement(int $userId, int $courseId): bool
    {
        return (bool) $this->db->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM course_enrolments
                             WHERE user_id=:user AND course_id=:course AND is_preview=FALSE
                               AND status IN ('assigned','active','completed','expired'))",
            ['user' => $userId, 'course' => $courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $lock = false): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM course_reviews WHERE id=:id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function forLearner(int $courseId, int $userId, bool $lock = false): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM course_reviews WHERE course_id=:course AND user_id=:user' . ($lock ? ' FOR UPDATE' : ''), ['course' => $courseId, 'user' => $userId]);
        return $row === false ? null : $row;
    }

    /** @return int|null the new review's id, or null when the learner already has one */
    public function create(int $courseId, int $userId, int $rating, string $comment, DateTimeImmutable $now): ?int
    {
        $id = $this->db->fetchOne(
            'INSERT INTO course_reviews (course_id,user_id,rating,comment,status,submitted_at,updated_at)
             VALUES (:course,:user,:rating,:comment,\'pending\',:now,:now)
             ON CONFLICT (course_id,user_id) DO NOTHING RETURNING id',
            ['course' => $courseId, 'user' => $userId, 'rating' => $rating, 'comment' => $comment, 'now' => $now->format(self::TIME)]
        );
        return $id === false || $id === null ? null : (int) $id;
    }

    /** A changed submission goes back to moderation. The published version is left as it is. */
    public function revise(int $id, int $rating, string $comment, DateTimeImmutable $now): int
    {
        return (int) $this->db->fetchOne(
            "UPDATE course_reviews SET rating=:rating, comment=:comment, status='pending', revision=revision+1, updated_at=:now
              WHERE id=:id RETURNING revision",
            ['id' => $id, 'rating' => $rating, 'comment' => $comment, 'now' => $now->format(self::TIME)]
        );
    }

    /** Publishes the current submission. @return int the moderation number of this decision */
    public function approve(int $id, int $moderatorId, ?string $note, DateTimeImmutable $now): int
    {
        return (int) $this->db->fetchOne(
            "UPDATE course_reviews SET status='approved', published_rating=rating, published_comment=comment, published_at=:now,
                    moderated_at=:now, moderated_by_user_id=:moderator, moderation_note=:note, moderation_count=moderation_count+1
              WHERE id=:id RETURNING moderation_count",
            ['id' => $id, 'moderator' => $moderatorId, 'note' => $note, 'now' => $now->format(self::TIME)]
        );
    }

    /**
     * Rejects the current submission. With $withdraw the published version is taken down as well;
     * without it, a version approved earlier stays published. @return int the moderation number
     */
    public function reject(int $id, int $moderatorId, ?string $note, bool $withdraw, DateTimeImmutable $now): int
    {
        $published = $withdraw ? ', published_rating=NULL, published_comment=NULL, published_at=NULL' : '';
        return (int) $this->db->fetchOne(
            "UPDATE course_reviews SET status='rejected', moderated_at=:now, moderated_by_user_id=:moderator, moderation_note=:note,
                    moderation_count=moderation_count+1{$published}
              WHERE id=:id RETURNING moderation_count",
            ['id' => $id, 'moderator' => $moderatorId, 'note' => $note, 'now' => $now->format(self::TIME)]
        );
    }

    /** Approved-review rating statistics for one course. */
    public function ratingSummary(int $courseId): CourseRatingSummary
    {
        return $this->ratingSummaries([$courseId])[$courseId];
    }

    /**
     * Approved-review rating statistics for several courses in one query.
     *
     * @param list<int> $courseIds
     * @return array<int,CourseRatingSummary> keyed by course id, every requested course present
     */
    public function ratingSummaries(array $courseIds): array
    {
        $courseIds = array_values(array_unique(array_filter($courseIds, static fn(int $id): bool => $id > 0)));
        $distributions = array_fill_keys($courseIds, []);
        if ($courseIds !== []) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT course_id, published_rating, COUNT(*) AS reviews FROM course_reviews
                  WHERE course_id IN (' . implode(',', $courseIds) . ') AND published_rating IS NOT NULL
                  GROUP BY course_id, published_rating'
            );
            foreach ($rows as $row) {
                $distributions[(int) $row['course_id']][(int) $row['published_rating']] = (int) $row['reviews'];
            }
        }
        return array_map(static fn(array $distribution): CourseRatingSummary => CourseRatingSummary::fromDistribution($distribution), $distributions);
    }

    /** Approved-review rating statistics across every course: the platform-wide prior for confidence-weighted ratings. */
    public function globalRatingSummary(): CourseRatingSummary
    {
        $distribution = [];
        foreach ($this->db->fetchAllAssociative('SELECT published_rating, COUNT(*) AS reviews FROM course_reviews WHERE published_rating IS NOT NULL GROUP BY published_rating') as $row) {
            $distribution[(int) $row['published_rating']] = (int) $row['reviews'];
        }
        return CourseRatingSummary::fromDistribution($distribution);
    }

    /**
     * Published reviews of a course, newest first: only what the public may see.
     *
     * @return list<array{id:int,rating:int,comment:string,published_at:string,reviewer:string}>
     */
    public function published(int $courseId, int $limit, int $offset): array
    {
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'], 'rating' => (int) $row['rating'], 'comment' => (string) $row['comment'],
            'published_at' => (string) $row['published_at'], 'reviewer' => (string) $row['reviewer'],
        ], $this->db->fetchAllAssociative(
            'SELECT r.id, r.published_rating AS rating, r.published_comment AS comment, r.published_at, ' . self::PUBLIC_NAME . ' AS reviewer
               FROM course_reviews r JOIN users u ON u.id = r.user_id
              WHERE r.course_id=:course AND r.published_rating IS NOT NULL
              ORDER BY r.published_at DESC, r.id DESC LIMIT :limit OFFSET :offset',
            ['course' => $courseId, 'limit' => max(1, $limit), 'offset' => max(0, $offset)]
        ));
    }

    /**
     * The ADMIN moderation list.
     *
     * @param array{status?:string,course_id?:int,rating?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function moderationList(array $filters, int $limit, int $offset): array
    {
        [$where, $bindings] = self::moderationFilter($filters);
        return $this->db->fetchAllAssociative(
            "SELECT r.*, c.title AS course_title, c.slug AS course_slug,
                    COALESCE(NULLIF(u.sort_name,''), 'User ' || u.id) AS learner_name, " . self::PUBLIC_NAME . " AS reviewer,
                    m.sort_name AS moderator_name
               FROM course_reviews r
               JOIN courses c ON c.id = r.course_id
               JOIN users u ON u.id = r.user_id
               LEFT JOIN users m ON m.id = r.moderated_by_user_id
              {$where}
              ORDER BY (r.status = 'pending') DESC, CASE WHEN r.status = 'pending' THEN r.updated_at END ASC, r.updated_at DESC, r.id DESC
              LIMIT :limit OFFSET :offset",
            $bindings + ['limit' => max(1, $limit), 'offset' => max(0, $offset)]
        );
    }

    /** @param array{status?:string,course_id?:int,rating?:int} $filters */
    public function moderationCount(array $filters): int
    {
        [$where, $bindings] = self::moderationFilter($filters);
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_reviews r {$where}", $bindings);
    }

    /** @return array{pending:int,approved:int,rejected:int} */
    public function statusCounts(): array
    {
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS reviews FROM course_reviews GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['reviews'];
        }
        return $counts;
    }

    public function pendingCount(): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_reviews WHERE status='pending'");
    }

    /**
     * Courses that have reviews, for the moderation filter.
     *
     * @return list<array{id:int,title:string}>
     */
    public function reviewedCourses(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title']], $this->db->fetchAllAssociative(
            'SELECT c.id, c.title FROM courses c WHERE EXISTS (SELECT 1 FROM course_reviews r WHERE r.course_id = c.id) ORDER BY lower(c.title), c.id'
        ));
    }

    /**
     * @param array{status?:string,course_id?:int,rating?:int} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function moderationFilter(array $filters): array
    {
        $conditions = [];
        $bindings = [];
        if (isset($filters['status'])) { $conditions[] = 'r.status = :status'; $bindings['status'] = $filters['status']; }
        if (isset($filters['course_id'])) { $conditions[] = 'r.course_id = :course'; $bindings['course'] = $filters['course_id']; }
        if (isset($filters['rating'])) { $conditions[] = 'r.rating = :rating'; $bindings['rating'] = $filters['rating']; }
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $bindings];
    }
}
