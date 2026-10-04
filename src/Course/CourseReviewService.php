<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Slug;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Course ratings and moderated learner reviews.
 *
 * A learner with a genuine entitlement to a course (see
 * {@see CourseReviewRepository::hasReviewEntitlement()}) rates it from one to five stars, may add a
 * comment, and can change both later; they hold one review per course. Every new or changed review
 * waits for ADMIN. Approval publishes it; rejection does not, and an earlier approved version stays
 * published unless ADMIN withdraws it too. So an edit to a published review never replaces the
 * public text until ADMIN approves the edit, and the public never sees a version ADMIN has not.
 *
 * Every change is written with its audit entry and analytics event in one transaction, and only
 * when something actually changed, so a repeated request records nothing new.
 */
final class CourseReviewService
{
    public const MAX_COMMENT = 2000;
    public const MAX_NOTE = 1000;
    public const PUBLIC_PAGE_SIZE = 25;

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly CourseReviewRepository $reviews,
        private readonly CourseRepository $courses,
        private readonly AuditRepository $audit,
        private readonly AnalyticsEventRecorder $analytics,
        private readonly ClockInterface $clock
    ) {
    }

    /**
     * The course a review page is for, published or not: a learner who studied a course that has
     * since been withdrawn from the catalogue may still review it.
     *
     * @return array<string,mixed>|null
     */
    public function course(string $slug): ?array
    {
        try {
            return $this->courses->findBySlug(Slug::validate($slug));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function canReview(int $userId, int $courseId): bool
    {
        return $this->reviews->hasReviewEntitlement($userId, $courseId);
    }

    /** @return array<string,mixed>|null */
    public function learnerReview(int $courseId, int $userId): ?array
    {
        return $this->reviews->forLearner($courseId, $userId);
    }

    /**
     * Saves a learner's review of a course: creates it, or revises the one they have.
     *
     * @return 'submitted'|'updated'|'unchanged'
     * @throws CourseReviewNotAllowedException when the learner has no entitlement to the course
     * @throws InvalidArgumentException for a rating outside 1-5 or an overlong comment
     */
    public function submit(int $userId, int $courseId, mixed $rating, mixed $comment): string
    {
        $rating = self::rating($rating);
        $comment = self::text($comment, self::MAX_COMMENT, 'A review');
        if (!$this->reviews->hasReviewEntitlement($userId, $courseId)) {
            throw new CourseReviewNotAllowedException('Only learners who have had access to this course can review it.');
        }
        return $this->transactions->run(function () use ($userId, $courseId, $rating, $comment): string {
            $now = $this->clock->now();
            $existing = $this->reviews->forLearner($courseId, $userId, true);
            if ($existing === null) {
                $id = $this->reviews->create($courseId, $userId, $rating, $comment, $now);
                // A concurrent first submission won the unique constraint: revise that one instead.
                $existing = $id === null ? $this->reviews->forLearner($courseId, $userId, true) : null;
                if ($id !== null) {
                    $this->audit->record($userId, 'course.review.submitted', ['course_id' => $courseId, 'review_id' => $id, 'rating' => $rating]);
                    $this->analytics->record(AnalyticsEventType::ReviewSubmitted, AnalyticsSource::CourseReview, ['user_id' => $userId, 'course_id' => $courseId],
                        ['review_id' => $id, 'rating' => $rating, 'revision' => 1, 'has_comment' => $comment !== ''], 'review_submitted:review:' . $id);
                    return 'submitted';
                }
            }
            if ($existing === null) {
                throw new CourseReviewNotAllowedException('The review could not be saved. Try again.');
            }
            if ((int) $existing['rating'] === $rating && (string) $existing['comment'] === $comment) {
                return 'unchanged';
            }
            $id = (int) $existing['id'];
            $revision = $this->reviews->revise($id, $rating, $comment, $now);
            $this->audit->record($userId, 'course.review.updated', ['course_id' => $courseId, 'review_id' => $id, 'rating' => $rating, 'revision' => $revision]);
            $this->analytics->record(AnalyticsEventType::ReviewUpdated, AnalyticsSource::CourseReview, ['user_id' => $userId, 'course_id' => $courseId],
                ['review_id' => $id, 'rating' => $rating, 'revision' => $revision, 'has_comment' => $comment !== '', 'previous_status' => (string) $existing['status']],
                'review_updated:review:' . $id . ':revision:' . $revision);
            return 'updated';
        });
    }

    /**
     * Publishes the revision ADMIN read. A review the learner has changed since then is refused, so
     * ADMIN never approves text they have not seen.
     *
     * @return bool false when the review was already approved
     */
    public function approve(int $reviewId, int $moderatorId, mixed $revision, mixed $note): bool
    {
        $note = self::note($note);
        return $this->transactions->run(function () use ($reviewId, $moderatorId, $revision, $note): bool {
            $review = $this->moderated($reviewId, $revision);
            if ($review['status'] === 'approved') { return false; }
            $decision = $this->reviews->approve($reviewId, $moderatorId, $note, $this->clock->now());
            $this->recordDecision(AnalyticsEventType::ReviewApproved, $review, $moderatorId, $decision, false);
            return true;
        });
    }

    /**
     * Declines the revision ADMIN read. A version approved earlier stays published unless
     * $withdraw; rejecting the published version itself always withdraws it.
     *
     * @return bool false when nothing changed
     */
    public function reject(int $reviewId, int $moderatorId, mixed $revision, mixed $note, bool $withdraw): bool
    {
        $note = self::note($note);
        return $this->transactions->run(function () use ($reviewId, $moderatorId, $revision, $note, $withdraw): bool {
            $review = $this->moderated($reviewId, $revision);
            $published = $review['published_rating'] !== null;
            $withdraw = $published && ($withdraw || $review['status'] === 'approved');
            if ($review['status'] === 'rejected' && !$withdraw) { return false; }
            $decision = $this->reviews->reject($reviewId, $moderatorId, $note, $withdraw, $this->clock->now());
            $this->recordDecision(AnalyticsEventType::ReviewRejected, $review, $moderatorId, $decision, $withdraw);
            return true;
        });
    }

    public function ratingSummary(int $courseId): CourseRatingSummary
    {
        return $this->reviews->ratingSummary($courseId);
    }

    /**
     * @param list<int> $courseIds
     * @return array<int,CourseRatingSummary>
     */
    public function ratingSummaries(array $courseIds): array
    {
        return $this->reviews->ratingSummaries($courseIds);
    }

    /** @return list<array{id:int,rating:int,comment:string,published_at:string,reviewer:string}> */
    public function publishedReviews(int $courseId, int $limit, int $offset): array
    {
        return $this->reviews->published($courseId, $limit, $offset);
    }

    /** A rating is a whole number of stars from 1 to 5, as an int or its decimal string. */
    public static function rating(mixed $rating): int
    {
        if (is_string($rating) && preg_match('/^[1-5]$/', trim($rating)) === 1) { return (int) trim($rating); }
        if (is_int($rating) && $rating >= 1 && $rating <= 5) { return $rating; }
        throw new InvalidArgumentException('Choose a rating from 1 to 5 stars.');
    }

    /** @return array<string,mixed> the review, locked */
    private function moderated(int $reviewId, mixed $revision): array
    {
        $review = $this->reviews->find($reviewId, true);
        if ($review === null) { throw new InvalidArgumentException('The review could not be found.'); }
        if (!is_scalar($revision) || (string) $revision !== (string) $review['revision']) {
            throw new InvalidArgumentException('The learner changed this review after it was opened. Read the new version before deciding.');
        }
        return $review;
    }

    /** @param array<string,mixed> $review */
    private function recordDecision(AnalyticsEventType $type, array $review, int $moderatorId, int $decision, bool $withdrawn): void
    {
        $reviewId = (int) $review['id'];
        $this->audit->record($moderatorId, $type === AnalyticsEventType::ReviewApproved ? 'course.review.approved' : 'course.review.rejected',
            ['course_id' => (int) $review['course_id'], 'review_id' => $reviewId, 'revision' => (int) $review['revision'], 'withdrawn' => $withdrawn]);
        $this->analytics->record($type, AnalyticsSource::Admin, ['user_id' => (int) $review['user_id'], 'course_id' => (int) $review['course_id']],
            ['review_id' => $reviewId, 'rating' => (int) $review['rating'], 'revision' => (int) $review['revision'], 'moderator_id' => $moderatorId, 'withdrawn' => $withdrawn],
            $type->value . ':review:' . $reviewId . ':decision:' . $decision);
    }

    private static function note(mixed $note): ?string
    {
        $note = self::text($note, self::MAX_NOTE, 'A moderation note');
        return $note === '' ? null : $note;
    }

    private static function text(mixed $value, int $max, string $what): string
    {
        if ($value !== null && !is_string($value)) { throw new InvalidArgumentException($what . ' must be text.'); }
        $value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
        if (mb_strlen($value) > $max) { throw new InvalidArgumentException($what . ' may be at most ' . $max . ' characters.'); }
        return $value;
    }
}
