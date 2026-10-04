<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseReviewNotAllowedException;
use CattoLearning\Course\CourseReviewRepository;
use CattoLearning\Course\CourseReviewService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Course ratings and moderated reviews at the service: who may review, what a rating may be, one
 * review per learner per course, moderation and the published version, the approved-only rating
 * statistics, what the public listing exposes, and one analytics event per real change.
 */
final class CourseReviewIntegrationTest extends TestCase
{
    private Database $db;
    private CourseReviewService $reviews;
    private CourseReviewRepository $repository;
    private DevelopmentFixture $fixture;
    private int $owner;
    private int $admin;
    private int $course;
    private int $otherCourse;
    private int $learner;

    protected function setUp(): void
    {
        $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->db->beginTransaction();
        $this->reviews = $container->get(CourseReviewService::class);
        $this->repository = $container->get(CourseReviewRepository::class);
        $this->fixture = new DevelopmentFixture($this->db);
        $this->owner = $this->fixture->createUser('Review owner');
        $this->admin = $this->fixture->createUser('Review moderator');
        $company = $this->fixture->createCompany($this->owner, 'Reviews ' . $this->fixture->suffix(), $this->fixture->suffix() . '.example.test');
        $this->course = $this->fixture->createCourse($this->owner, $company, 'reviews-' . $this->fixture->suffix(), 'Reviewed course', 'published');
        $this->otherCourse = $this->fixture->createCourse($this->owner, $company, 'reviews-other-' . $this->fixture->suffix(), 'Other course', 'published');
        $this->learner = $this->learner('Jane', 'Doe', 'active');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    public function testAnEntitledLearnerSubmitsAPendingReviewThatIsNotPublic(): void
    {
        self::assertSame('submitted', $this->reviews->submit($this->learner, $this->course, '4', '  Clear and practical.  '));
        $review = $this->review($this->learner);
        self::assertSame('pending', $review['status']);
        self::assertSame(4, (int) $review['rating']);
        self::assertSame('Clear and practical.', $review['comment'], 'The comment is trimmed.');
        self::assertNull($review['published_rating']);
        self::assertSame([], $this->repository->published($this->course, 25, 0), 'A pending review is not public.');
        self::assertSame(0, $this->reviews->ratingSummary($this->course)->count, 'A pending review is not counted.');
        self::assertSame(1, $this->events('review_submitted'));
    }

    public function testOnlyAGenuineEntitlementQualifiesIncludingAnExpiredOne(): void
    {
        $expired = $this->learner('Past', 'Learner', 'expired');
        $assigned = $this->learner('New', 'Learner', 'assigned');
        $completed = $this->learner('Done', 'Learner', 'completed');
        self::assertSame('submitted', $this->reviews->submit($expired, $this->course, 5, ''), 'A learner whose access expired may still review.');
        self::assertSame('submitted', $this->reviews->submit($assigned, $this->course, 3, ''));
        self::assertSame('submitted', $this->reviews->submit($completed, $this->course, 4, ''));

        $none = $this->fixture->createUser('No access');
        $refunded = $this->learner('Refunded', 'Learner', 'cancelled');
        $previewer = $this->fixture->createUser('Previewing admin');
        $this->fixture->createEnrolment($previewer, $this->course, $this->owner, 2592000, true, 'active');
        $otherCourseOnly = $this->fixture->createUser('Other course learner');
        $this->fixture->createEnrolment($otherCourseOnly, $this->otherCourse, $this->owner, 2592000, false, 'active');
        foreach (['no enrolment' => $none, 'cancelled (refunded) enrolment' => $refunded, 'ADMIN preview enrolment' => $previewer, 'another course only' => $otherCourseOnly] as $case => $user) {
            self::assertFalse($this->reviews->canReview($user, $this->course), $case);
            try {
                $this->reviews->submit($user, $this->course, 5, 'Should not be saved.');
                self::fail($case . ' must not be able to review.');
            } catch (CourseReviewNotAllowedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertFalse($this->reviews->canReview($this->learner, 999999999), 'A course that does not exist cannot be reviewed.');
        $this->expectException(CourseReviewNotAllowedException::class);
        $this->reviews->submit($this->learner, 999999999, 5, '');
    }

    public function testRatingsAreWholeStarsFromOneToFive(): void
    {
        foreach ([1, 2, 3, 4, 5, '1', '5', ' 3 '] as $valid) {
            self::assertSame((int) trim((string) $valid), CourseReviewService::rating($valid));
        }
        foreach ([0, 6, -1, '0', '6', '3.5', 3.0, '', 'five', null, true, ['4'], '١'] as $invalid) {
            try {
                CourseReviewService::rating($invalid);
                self::fail('Rating ' . var_export($invalid, true) . ' must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([0, 6, 'x'] as $invalid) {
            try {
                $this->reviews->submit($this->learner, $this->course, $invalid, '');
                self::fail('A review with rating ' . var_export($invalid, true) . ' must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertNull($this->review($this->learner), 'No refused review is stored.');
    }

    public function testTheCommentIsBoundedAndTheDatabaseRefusesInvalidRatings(): void
    {
        $this->reviews->submit($this->learner, $this->course, 4, str_repeat('é', CourseReviewService::MAX_COMMENT));
        $this->expectException(InvalidArgumentException::class);
        $this->reviews->submit($this->learner, $this->course, 4, str_repeat('a', CourseReviewService::MAX_COMMENT + 1));
    }

    public function testALearnerHasOneReviewPerCourseAndEditsIt(): void
    {
        $this->reviews->submit($this->learner, $this->course, 3, 'First thoughts.');
        self::assertSame('unchanged', $this->reviews->submit($this->learner, $this->course, '3', 'First thoughts.'), 'Saving the same review again changes nothing.');
        self::assertSame('updated', $this->reviews->submit($this->learner, $this->course, 4, 'Second thoughts.'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_reviews WHERE course_id=:c AND user_id=:u', ['c' => $this->course, 'u' => $this->learner]));
        $review = $this->review($this->learner);
        self::assertSame('Second thoughts.', $review['comment']);
        self::assertSame(2, (int) $review['revision']);
        self::assertSame(1, $this->events('review_submitted'));
        self::assertSame(1, $this->events('review_updated'), 'The unchanged save recorded nothing.');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->db->executeStatement("INSERT INTO course_reviews (course_id,user_id,rating,submitted_at,updated_at) VALUES (:c,:u,5,NOW(),NOW())", ['c' => $this->course, 'u' => $this->learner]);
    }

    public function testApprovalPublishesAndRejectionDoesNotAndRepeatsChangeNothing(): void
    {
        $this->reviews->submit($this->learner, $this->course, 5, 'Excellent.');
        $review = $this->review($this->learner);
        self::assertTrue($this->reviews->approve((int) $review['id'], $this->admin, (string) $review['revision'], 'Fine.'));
        self::assertFalse($this->reviews->approve((int) $review['id'], $this->admin, (string) $review['revision'], ''), 'Approving twice changes nothing.');
        $public = $this->repository->published($this->course, 25, 0);
        self::assertCount(1, $public);
        self::assertSame('Excellent.', $public[0]['comment']);
        self::assertSame(1, $this->events('review_approved'), 'The repeated approval recorded no event.');

        $other = $this->learner('Sam', 'Smith', 'active');
        $this->reviews->submit($other, $this->course, 1, 'Spam spam spam.');
        $spam = $this->review($other);
        self::assertTrue($this->reviews->reject((int) $spam['id'], $this->admin, $spam['revision'], 'Not about the course.', false));
        self::assertFalse($this->reviews->reject((int) $spam['id'], $this->admin, $spam['revision'], '', false), 'Rejecting twice changes nothing.');
        self::assertSame('rejected', $this->review($other)['status']);
        self::assertCount(1, $this->repository->published($this->course, 25, 0), 'A rejected review is not public.');
        self::assertSame(1, $this->events('review_rejected'));
        self::assertNotNull($this->review($other), 'Rejection keeps the review; it is never deleted.');
    }

    public function testAnEditedApprovedReviewKeepsItsApprovedVersionPublicUntilTheEditIsApproved(): void
    {
        $this->reviews->submit($this->learner, $this->course, 5, 'Original text.');
        $review = $this->review($this->learner);
        $this->reviews->approve((int) $review['id'], $this->admin, $review['revision'], null);

        self::assertSame('updated', $this->reviews->submit($this->learner, $this->course, 2, 'Edited text.'));
        $edited = $this->review($this->learner);
        self::assertSame('pending', $edited['status'], 'An edit goes back to moderation.');
        $public = $this->repository->published($this->course, 25, 0);
        self::assertSame('Original text.', $public[0]['comment'], 'The approved version stays published.');
        self::assertSame(5, $public[0]['rating']);
        self::assertSame(5.0, $this->reviews->ratingSummary($this->course)->average, 'The rating still counts the approved version only.');

        try {
            $this->reviews->approve((int) $edited['id'], $this->admin, $review['revision'], null);
            self::fail('Approving a revision ADMIN has not seen must be refused.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->reviews->reject((int) $edited['id'], $this->admin, $edited['revision'], 'Edit declined.', false);
        self::assertSame('Original text.', $this->repository->published($this->course, 25, 0)[0]['comment'], 'Rejecting the edit leaves the approved version published.');

        $this->reviews->submit($this->learner, $this->course, 3, 'Third try.');
        $third = $this->review($this->learner);
        $this->reviews->approve((int) $third['id'], $this->admin, $third['revision'], null);
        $public = $this->repository->published($this->course, 25, 0);
        self::assertSame('Third try.', $public[0]['comment'], 'An approved edit replaces the published version.');
        self::assertSame(3.0, $this->reviews->ratingSummary($this->course)->average);

        // Rejecting a published review takes it down.
        $this->reviews->reject((int) $third['id'], $this->admin, $third['revision'], null, false);
        self::assertSame([], $this->repository->published($this->course, 25, 0));
        self::assertSame(0, $this->reviews->ratingSummary($this->course)->count);
        self::assertSame(2, $this->events('review_approved'));
        self::assertSame(2, $this->events('review_rejected'));
    }

    public function testAnEarlierPublishedVersionCanBeWithdrawnWithTheRejectedEdit(): void
    {
        $this->reviews->submit($this->learner, $this->course, 4, 'Good.');
        $review = $this->review($this->learner);
        $this->reviews->approve((int) $review['id'], $this->admin, $review['revision'], null);
        $this->reviews->submit($this->learner, $this->course, 1, 'Something offensive.');
        $edit = $this->review($this->learner);
        self::assertTrue($this->reviews->reject((int) $edit['id'], $this->admin, $edit['revision'], 'Withdrawn.', true));
        self::assertSame([], $this->repository->published($this->course, 25, 0));
        $withdrawn = $this->db->fetchOne("SELECT metadata->>'withdrawn' FROM analytics_events WHERE event_type='review_rejected' AND course_id=:c", ['c' => $this->course]);
        self::assertSame('true', $withdrawn);
    }

    public function testOnlyApprovedReviewsMakeTheRatingAverageCountAndDistribution(): void
    {
        foreach ([5, 5, 4, 2] as $index => $stars) {
            $user = $this->learner('Rater' . $index, 'Approved', 'active');
            $this->reviews->submit($user, $this->course, $stars, '');
            $review = $this->review($user);
            $this->reviews->approve((int) $review['id'], $this->admin, $review['revision'], null);
        }
        $pending = $this->learner('Pending', 'Rater', 'active');
        $this->reviews->submit($pending, $this->course, 1, '');
        $rejected = $this->learner('Rejected', 'Rater', 'active');
        $this->reviews->submit($rejected, $this->course, 1, '');
        $review = $this->review($rejected);
        $this->reviews->reject((int) $review['id'], $this->admin, $review['revision'], null, false);

        $summary = $this->reviews->ratingSummary($this->course);
        self::assertSame(4, $summary->count);
        self::assertSame(4.0, $summary->average);
        self::assertSame([5 => 2, 4 => 1, 3 => 0, 2 => 1, 1 => 0], $summary->distribution);
        $display = $summary->toArray();
        self::assertSame('4.0', $display['average_label']);
        self::assertSame(4, $display['filled_stars']);
        self::assertSame(50, $display['distribution'][0]['percent']);

        $both = $this->reviews->ratingSummaries([$this->course, $this->otherCourse]);
        self::assertSame(4, $both[$this->course]->count);
        self::assertSame(0, $both[$this->otherCourse]->count, 'A course without approved reviews is present, with no rating.');
        self::assertNull($both[$this->otherCourse]->average);
    }

    public function testThePublicListingShowsAFirstNameAndInitialAndNothingPrivate(): void
    {
        $this->db->executeStatement("INSERT INTO user_emails (user_id,email,is_primary,verified_at) VALUES (:u,:e,TRUE,NOW())", ['u' => $this->learner, 'e' => 'jane-' . $this->fixture->suffix() . '@example.test']);
        $this->reviews->submit($this->learner, $this->course, 4, 'Useful.');
        $review = $this->review($this->learner);
        $this->reviews->approve((int) $review['id'], $this->admin, $review['revision'], 'Private moderation note.');
        $public = $this->repository->published($this->course, 25, 0);
        self::assertSame(['id', 'rating', 'comment', 'published_at', 'reviewer'], array_keys($public[0]));
        self::assertSame('Jane D.', $public[0]['reviewer']);
        $serialised = json_encode($public, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@', $serialised);
        self::assertStringNotContainsString('Private moderation note', $serialised);
        self::assertStringNotContainsString('"user_id"', $serialised);
    }

    public function testReviewEventsCarryReferencesButNeverTheText(): void
    {
        $this->reviews->submit($this->learner, $this->course, 4, 'Secret words in the review.');
        $review = $this->review($this->learner);
        $this->reviews->approve((int) $review['id'], $this->admin, $review['revision'], null);
        $events = $this->db->fetchAllAssociative('SELECT event_type, user_id, course_id, metadata::text AS metadata, idempotency_key FROM analytics_events WHERE course_id=:c ORDER BY id', ['c' => $this->course]);
        self::assertSame(['review_submitted', 'review_approved'], array_column($events, 'event_type'));
        foreach ($events as $event) {
            self::assertSame($this->learner, (int) $event['user_id']);
            self::assertSame((int) $review['id'], json_decode((string) $event['metadata'], true)['review_id']);
            self::assertStringNotContainsString('Secret words', (string) $event['metadata']);
            self::assertNotNull($event['idempotency_key']);
        }
        self::assertSame($this->admin, json_decode((string) $events[1]['metadata'], true)['moderator_id']);
    }

    private function learner(string $first, string $last, string $status): int
    {
        $user = $this->fixture->createUser($first . ' ' . $last);
        $this->db->executeStatement('UPDATE users SET first_name=:f, last_name=:l WHERE id=:id', ['f' => $first, 'l' => $last, 'id' => $user]);
        $this->fixture->createEnrolment($user, $this->course, $this->owner, 2592000, false, $status);
        return $user;
    }

    /** @return array<string,mixed>|null */
    private function review(int $user): ?array
    {
        return $this->repository->forLearner($this->course, $user);
    }

    private function events(string $type): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE event_type=:t AND course_id=:c', ['t' => $type, 'c' => $this->course]);
    }
}
