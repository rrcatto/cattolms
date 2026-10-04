<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\Popularity\CoursePopularityCalculator;
use CattoLearning\Course\Popularity\CoursePopularityRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\SortOrder;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\DevelopmentFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Course popularity against the database: each signal read from its source of truth, eligibility,
 * the stored snapshot and the query API. Each test runs in a transaction that is rolled back, and
 * compares only the courses it creates, because the development database holds other courses.
 */
final class CoursePopularityIntegrationTest extends TestCase
{
    private Database $db;
    private CoursePopularityCalculator $calculator;
    private CoursePopularityRepository $popularity;
    private AnalyticsEventRepository $events;
    private DevelopmentFixture $fixture;
    private DateTimeImmutable $at;
    private int $owner;
    private int $company;
    /** Unique to this test's courses, so searches find only them. */
    private string $suffix;

    protected function setUp(): void
    {
        $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->db->beginTransaction();
        $this->calculator = $container->get(CoursePopularityCalculator::class);
        $this->popularity = $container->get(CoursePopularityRepository::class);
        $this->events = $container->get(AnalyticsEventRepository::class);
        $this->fixture = new DevelopmentFixture($this->db);
        $this->at = new DateTimeImmutable('2026-10-01 12:00:00+00:00');
        $this->suffix = $this->fixture->suffix();
        $this->owner = $this->fixture->createUser('Popularity owner');
        $this->company = $this->fixture->createCompany($this->owner, 'Popularity ' . $this->fixture->suffix(), $this->fixture->suffix() . '.example.test');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    public function testViewsAreDistinctDiscoveryViewersInsideTheWindow(): void
    {
        $course = $this->course('views');
        $visitor = Uuid::v4();
        for ($i = 0; $i < 10; $i++) { $this->view($course, 'course_detail', null, $visitor, 2); }
        $signedIn = $this->fixture->createUser('Viewer');
        $this->view($course, 'course_detail', $signedIn, Uuid::v4(), 3);
        $this->view($course, 'public_preview', $signedIn, Uuid::v4(), 4);
        $learner = $this->fixture->createUser('Reader');
        for ($i = 0; $i < 30; $i++) { $this->view($course, 'learner_reader', $learner, Uuid::v4(), 1); }
        $this->view($course, 'course_detail', null, Uuid::v4(), 45);

        $this->calculator->recalculate($this->at);
        $row = $this->popularity->forCourse($course);
        self::assertNotNull($row);
        self::assertSame(2, $row['unique_views'], 'Ten views by one visitor and two by one person are two viewers; the view before the window is not counted.');
        self::assertSame(1, $row['learner_readers'], 'Thirty lessons by one learner are one reader.');
    }

    public function testFavouritesAreTheCurrentFavouritesNotTheEventHistory(): void
    {
        $course = $this->course('favourites');
        foreach (['A', 'B', 'C'] as $name) {
            $user = $this->fixture->createUser('Fan ' . $name);
            $this->db->executeStatement('INSERT INTO course_favourites (user_id, course_id) VALUES (:u, :c)', ['u' => $user, 'c' => $course]);
            $this->events->append('course_favourite_added', 'course_detail', $this->at->modify('-1 day'), ['user_id' => $user, 'course_id' => $course], [], null);
            $this->events->append('course_favourite_added', 'course_detail', $this->at->modify('-1 day'), ['user_id' => $user, 'course_id' => $course], [], null);
        }
        $this->calculator->recalculate($this->at);
        self::assertSame(3, $this->popularity->forCourse($course)['favourites'] ?? null);
    }

    public function testPurchasesAndRefundsAreCountedOnceAndRefundsMeasuredInUnits(): void
    {
        $course = $this->course('sales');
        $variant = $this->fixture->createPriceVariant($course, $this->owner, 2592000, 10000);
        $order = (int) $this->db->fetchOne(
            "INSERT INTO commerce_orders (public_id, purchaser_user_id, state, total_minor, currency, snapshot, placed_at, payment_due_at, company_id, company_purchase_key)
             VALUES (:p, :u, 'fulfilled', 40000, 'ZAR', '{}'::jsonb, :placed, :due, :company, :key) RETURNING id",
            ['p' => Uuid::v4(), 'u' => $this->owner, 'placed' => $this->at->modify('-5 days')->format(DATE_ATOM), 'due' => $this->at->modify('-4 days')->format(DATE_ATOM), 'company' => $this->company, 'key' => Uuid::v4()]
        );
        $line = (int) $this->db->fetchOne(
            "INSERT INTO commerce_order_items (order_id, variant_id, course_id, amount_minor, access_period_seconds, snapshot, company_id, quantity, product_type)
             VALUES (:o, :v, :c, 40000, 2592000, '{}'::jsonb, :company, 4, 'company_credit') RETURNING id",
            ['o' => $order, 'v' => $variant, 'c' => $course, 'company' => $this->company]
        );
        $purchase = ['course_id' => $course, 'order_id' => $order, 'order_item_id' => $line];
        self::assertTrue($this->events->append('course_purchased', 'checkout', $this->at->modify('-5 days'), $purchase, ['quantity' => 4, 'amount_minor' => 40000], 'course_purchased:order_item:' . $line));
        self::assertFalse($this->events->append('course_purchased', 'checkout', $this->at->modify('-5 days'), $purchase, ['quantity' => 4, 'amount_minor' => 40000], 'course_purchased:order_item:' . $line), 'A replayed purchase is not recorded twice.');
        $this->events->append('course_purchased', 'checkout', $this->at->modify('-3 days'), ['course_id' => $course], ['quantity' => 1, 'amount_minor' => 10000], 'course_purchased:test:' . Uuid::v4());
        $this->events->append('course_purchased', 'checkout', $this->at->modify('-60 days'), ['course_id' => $course], ['quantity' => 1, 'amount_minor' => 10000], 'course_purchased:test:' . Uuid::v4());
        // Half a unit's price back on the four-unit line, and a refund whose order line is unknown.
        $this->events->append('course_refunded', 'admin', $this->at->modify('-2 days'), $purchase, ['refund_id' => 1, 'quantity' => 1, 'amount_minor' => 5000, 'full_refund' => false], 'course_refunded:test:' . Uuid::v4());
        $this->events->append('course_refunded', 'admin', $this->at->modify('-1 day'), ['course_id' => $course], ['refund_id' => 2, 'quantity' => 1, 'amount_minor' => 10000, 'full_refund' => true], 'course_refunded:test:' . Uuid::v4());

        $this->calculator->recalculate($this->at);
        $row = $this->popularity->forCourse($course) ?? [];
        self::assertSame('paid', $row['offer']);
        self::assertSame(2, $row['purchases'], 'Two paid lines in the window; the replay and the purchase 60 days ago are not counted.');
        self::assertSame(5, $row['purchased_units']);
        self::assertSame(2, $row['refunds']);
        self::assertEqualsWithDelta(1.5, $row['refunded_units'], 0.0001, 'The partial refund is half a unit.');
        self::assertEqualsWithDelta(1.5 / (5 + 5), $row['refund_ratio'], 0.0001);
        self::assertGreaterThan(0.0, $row['refund_penalty_points']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM analytics_events WHERE event_type='course_purchased' AND course_id=:c AND order_item_id=:l", ['c' => $course, 'l' => $line]), 'The purchase event itself is kept.');
    }

    public function testOnlyApprovedReviewsCount(): void
    {
        $course = $this->course('reviews');
        $this->review($course, 'approved', 4, 4);
        $this->review($course, 'pending', 1, null);
        $this->review($course, 'rejected', 1, null);
        $this->review($course, 'pending', 1, 5); // an edit waiting for moderation: its approved 5 still counts
        $this->calculator->recalculate($this->at);
        $row = $this->popularity->forCourse($course) ?? [];
        self::assertSame(2, $row['approved_reviews']);
        self::assertEqualsWithDelta(4.5, $row['average_rating'], 0.0001);
        self::assertNotNull($row['weighted_rating']);
        self::assertGreaterThan(0.0, $row['rating_points']);
    }

    public function testMomentumComparesTheLatestPeriodWithThePreviousOne(): void
    {
        $course = $this->course('momentum');
        for ($i = 0; $i < 6; $i++) { $this->view($course, 'course_detail', null, Uuid::v4(), 2); }
        $this->view($course, 'course_detail', null, Uuid::v4(), 10);
        $this->calculator->recalculate($this->at);
        $row = $this->popularity->forCourse($course) ?? [];
        self::assertSame(6, $row['recent_views']);
        self::assertSame(1, $row['previous_views']);
        self::assertEqualsWithDelta(11 / 6, $row['momentum_growth'], 0.0001);
        self::assertGreaterThan(0.0, $row['momentum_points']);
    }

    public function testOnlyPublishedCoursesTakePartWhateverTheirHistory(): void
    {
        $draft = $this->course('draft', 'draft');
        $retired = $this->course('retired', 'retired');
        $published = $this->course('published');
        foreach ([$draft, $retired] as $course) {
            for ($i = 0; $i < 20; $i++) { $this->view($course, 'course_detail', null, Uuid::v4(), 1); }
        }
        $this->view($published, 'course_detail', null, Uuid::v4(), 1);
        $this->calculator->recalculate($this->at);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_popularity WHERE course_id IN (:a, :b)', ['a' => $draft, 'b' => $retired]));
        self::assertNull($this->popularity->forCourse($draft));
        self::assertNotNull($this->popularity->forCourse($published));

        $this->db->executeStatement("UPDATE courses SET status='retired' WHERE id=:id", ['id' => $published]);
        self::assertNull($this->popularity->forCourse($published), 'A course retired since the calculation is not served.');
        self::assertNotContains($published, array_column($this->popularity->popularCourses(100), 'course_id'));
    }

    public function testABalancedCourseOutranksOneSignalAndOldActivityDoesNotCount(): void
    {
        $balanced = $this->course('balanced');
        $viewsOnly = $this->course('views-only');
        $historic = $this->course('historic');
        for ($i = 0; $i < 10; $i++) { $this->view($balanced, 'course_detail', null, Uuid::v4(), 5); }
        foreach (['X', 'Y'] as $name) {
            $this->db->executeStatement('INSERT INTO course_favourites (user_id, course_id) VALUES (:u, :c)', ['u' => $this->fixture->createUser('Fan ' . $name), 'c' => $balanced]);
            $this->events->append('course_purchased', 'checkout', $this->at->modify('-4 days'), ['course_id' => $balanced], ['quantity' => 1], 'course_purchased:test:' . Uuid::v4());
        }
        $this->review($balanced, 'approved', 5, 5);
        for ($i = 0; $i < 40; $i++) { $this->view($viewsOnly, 'course_detail', null, Uuid::v4(), 5); }
        $this->db->executeStatement(
            "INSERT INTO analytics_events (event_type, source, occurred_at, visitor_id, course_id) SELECT 'course_view', 'course_detail', :at, gen_random_uuid(), :c FROM generate_series(1, 500)",
            ['at' => $this->at->modify('-90 days')->format(DATE_ATOM), 'c' => $historic]
        );
        $this->view($historic, 'course_detail', null, Uuid::v4(), 2);

        $this->calculator->recalculate($this->at);
        $rows = [$balanced => $this->popularity->forCourse($balanced), $viewsOnly => $this->popularity->forCourse($viewsOnly), $historic => $this->popularity->forCourse($historic)];
        self::assertLessThan($rows[$viewsOnly]['rank'] ?? 0, $rows[$balanced]['rank'] ?? PHP_INT_MAX, 'Views, favourites, sales and a review beat four times the views alone.');
        self::assertSame(1, $rows[$historic]['unique_views'] ?? null, 'Five hundred viewers ninety days ago are outside the window.');
        self::assertLessThan($rows[$historic]['rank'] ?? 0, $rows[$viewsOnly]['rank'] ?? PHP_INT_MAX);
    }

    public function testRecalculationReplacesTheSnapshotWithOneCompleteRun(): void
    {
        $course = $this->course('snapshot');
        $this->view($course, 'course_detail', null, Uuid::v4(), 1);
        $first = $this->calculator->recalculate($this->at->modify('-1 hour'));
        $second = $this->calculator->recalculate($this->at);
        self::assertNotSame($first['run_id'], $second['run_id']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_popularity_runs'), 'Only the current run is kept.');
        $published = (int) $this->db->fetchOne("SELECT COUNT(*) FROM courses WHERE status='published'");
        self::assertSame($published, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_popularity WHERE run_id=:r', ['r' => $second['run_id']]));
        self::assertSame($published, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_popularity'), 'No row of the earlier run remains.');
        self::assertSame($published, $second['courses']);

        $run = $this->popularity->currentRun() ?? [];
        self::assertSame($second['run_id'], $run['id']);
        self::assertSame($this->at->getTimestamp(), (new DateTimeImmutable($run['calculated_at']))->getTimestamp());
        self::assertSame($this->at->modify('-30 days')->getTimestamp(), (new DateTimeImmutable($run['window_start']))->getTimestamp());
        self::assertSame($this->at->getTimestamp(), (new DateTimeImmutable($run['window_end']))->getTimestamp());
        self::assertSame(0.35, $run['parameters']['weights']['purchases']);
        $row = $this->popularity->forCourse($course) ?? [];
        foreach (['view_points', 'favourite_points', 'purchase_points', 'refund_penalty_points', 'rating_points', 'momentum_points', 'unique_views', 'favourites', 'purchases', 'refunds', 'approved_reviews', 'momentum_growth'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertEqualsWithDelta($row['view_points'] + $row['favourite_points'] + $row['purchase_points'] + $row['rating_points'] + $row['momentum_points'], $row['score'], 0.0011);
    }

    public function testTheQueryApiServesTheRankingWithoutZeroScoresOrUnsaleableCourses(): void
    {
        $top = $this->course('top');
        $this->fixture->createPriceVariant($top, $this->owner);
        $unpriced = $this->course('unpriced');
        $silent = $this->course('silent');
        for ($i = 0; $i < 30; $i++) { $this->view($top, 'course_detail', null, Uuid::v4(), 1); }
        for ($i = 0; $i < 30; $i++) { $this->view($unpriced, 'course_detail', null, Uuid::v4(), 1); }
        $this->calculator->recalculate($this->at);

        $popular = $this->popularity->popularCourses(500);
        $ids = array_column($popular, 'course_id');
        self::assertContains($top, $ids);
        self::assertNotContains($silent, $ids, 'A course with no signal at all is not popular.');
        self::assertSame(0.0, $this->popularity->forCourse($silent)['score'] ?? null, 'It is still ranked, with zero.');
        $scores = array_column($popular, 'score');
        $sorted = $scores; rsort($sorted);
        self::assertSame($sorted, $scores, 'Best first.');
        self::assertCount(min(3, count($popular)), $this->popularity->popularCourses(3));
        self::assertNotContains($unpriced, array_column($this->popularity->popularCourses(500, saleableOnly: true), 'course_id'));
        self::assertContains($top, array_column($this->popularity->popularCourses(500, saleableOnly: true), 'course_id'));

        $suffix = $this->suffix;
        self::assertSame(3, $this->popularity->rankedCount($suffix, '', false));
        self::assertSame(1, $this->popularity->rankedCount($suffix, 'paid', false));
        self::assertSame(2, $this->popularity->rankedCount($suffix, 'none', false));
        $page = $this->popularity->ranked($suffix, '', false, SortOrder::create('rank', 'asc', array_keys(CoursePopularityRepository::SORTS), 'rank'), 2, 0);
        self::assertCount(2, $page);
        self::assertSame($silent, $this->popularity->ranked($suffix, '', false, SortOrder::create('rank', 'asc', array_keys(CoursePopularityRepository::SORTS), 'rank'), 2, 2)[0]['course_id'] ?? null);
        self::assertSame($silent, $this->popularity->ranked($suffix, '', false, SortOrder::create('views', 'asc', array_keys(CoursePopularityRepository::SORTS), 'rank'), 1, 0)[0]['course_id'] ?? null);
    }

    private function course(string $label, string $status = 'published'): int
    {
        return $this->fixture->createCourse($this->owner, $this->company, 'popularity-' . $label . '-' . $this->suffix, 'Popularity ' . $label . ' ' . $this->suffix, $status);
    }

    private function view(int $course, string $source, ?int $user, ?string $visitor, int $daysAgo): void
    {
        $this->events->append('course_view', $source, $this->at->modify('-' . $daysAgo . ' days'), ['course_id' => $course, 'user_id' => $user, 'visitor_id' => $visitor], ['context' => 'course_page'], null);
    }

    private function review(int $course, string $status, int $rating, ?int $published): void
    {
        $this->db->executeStatement(
            'INSERT INTO course_reviews (course_id, user_id, rating, comment, status, revision, published_rating, published_comment, published_at, submitted_at, updated_at)
             VALUES (:c, :u, :r, \'\', :s, 1, :p, :pc, :pa, :at, :at)',
            ['c' => $course, 'u' => $this->fixture->createUser('Reviewer ' . $status), 'r' => $rating, 's' => $status, 'p' => $published,
                'pc' => $published === null ? null : '', 'pa' => $published === null ? null : $this->at->format(DATE_ATOM), 'at' => $this->at->format(DATE_ATOM)]
        );
    }
}
