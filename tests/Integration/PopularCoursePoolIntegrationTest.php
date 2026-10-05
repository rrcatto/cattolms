<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseFavouriteService;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use PHPUnit\Framework\TestCase;

/**
 * The home page's popular-course pool: the stored ranking first, the newest published courses
 * filling what it leaves, no duplicates, nothing unpublished, a bounded size, ordinary card data,
 * and the shared favourite marking. Each test owns the ranking inside a transaction that is rolled
 * back, so the development database is left as it was.
 */
final class PopularCoursePoolIntegrationTest extends TestCase
{
    private Database $db;
    private CourseService $courses;
    private CourseFavouriteService $favourites;
    private DevelopmentFixture $fixture;
    private int $owner;
    private int $company;
    private string $suffix;

    protected function setUp(): void
    {
        $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->db->beginTransaction();
        $this->courses = $container->get(CourseService::class);
        $this->favourites = $container->get(CourseFavouriteService::class);
        $this->fixture = new DevelopmentFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->owner = $this->fixture->createUser('Pool owner');
        $this->company = $this->fixture->createCompany($this->owner, 'Pool ' . $this->suffix, $this->suffix . '.example.test');
        $this->db->executeStatement('DELETE FROM course_popularity_runs');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    public function testRankedCoursesComeFirstInRankOrder(): void
    {
        $second = $this->course('second');
        $first = $this->course('first');
        $this->rank([$first => 80.0, $second => 40.0]);
        $pool = $this->courses->popularCoursePool(5);
        self::assertSame([$first, $second], array_slice(self::ids($pool), 0, 2), 'The ranking decides the order, not publication date.');
    }

    public function testNewestPublishedCoursesFillTheRestWithoutDuplicates(): void
    {
        $ranked = $this->course('ranked', '-30 days');
        $newest = $this->course('newest', '+2 days');
        $newer = $this->course('newer', '+1 day');
        $this->rank([$ranked => 50.0, $newest => 20.0]);
        $pool = $this->courses->popularCoursePool(4);
        $ids = self::ids($pool);
        self::assertCount(4, $pool);
        self::assertSame([$ranked, $newest, $newer], array_slice($ids, 0, 3), 'After the ranking, the newest published course not already taken.');
        self::assertSame($ids, array_values(array_unique($ids)), 'No course appears twice.');
    }

    public function testCoursesWithNoScoreOrNoLongerPublishedAreNotRanked(): void
    {
        $draft = $this->course('draft', '+3 days', 'draft');
        $retired = $this->course('retired', '+3 days');
        $silent = $this->course('silent');
        $popular = $this->course('popular');
        $this->rank([$draft => 90.0, $retired => 85.0, $silent => 0.0, $popular => 10.0]);
        $this->db->executeStatement("UPDATE courses SET status='retired' WHERE id=:id", ['id' => $retired]);
        $ids = self::ids($this->courses->popularCoursePool(8));
        self::assertSame($popular, $ids[0], 'The first card is the best-ranked course that is published and scored.');
        self::assertNotContains($draft, $ids);
        self::assertNotContains($retired, $ids);
    }

    public function testThePoolIsBoundedAndCarriesCardData(): void
    {
        $pool = $this->courses->popularCoursePool();
        self::assertLessThanOrEqual(CourseService::POPULAR_POOL_SIZE, count($pool));
        self::assertCount(min(CourseService::POPULAR_POOL_SIZE, (int) $this->db->fetchOne("SELECT COUNT(*) FROM courses WHERE status='published'")), $pool);
        foreach (['slug', 'title', 'default_price_label', 'rating', 'tags', 'category_path', 'cover_initial'] as $key) {
            self::assertArrayHasKey($key, $pool[0], 'Cards on the home page carry the catalogue card data, including ' . $key . '.');
        }
        self::assertCount(1, $this->courses->popularCoursePool(1));
    }

    public function testNoPublishedCoursesGivesAnEmptyPool(): void
    {
        $this->db->executeStatement("UPDATE courses SET status='draft' WHERE status='published'");
        self::assertSame([], $this->courses->popularCoursePool());
    }

    public function testFavouritesAreMarkedForTheSignedInReaderOnly(): void
    {
        $liked = $this->course('liked');
        $other = $this->course('other');
        $reader = $this->fixture->createUser('Pool reader');
        $this->db->executeStatement('INSERT INTO course_favourites (user_id, course_id) VALUES (:u, :c)', ['u' => $reader, 'c' => $liked]);
        $this->rank([$liked => 60.0, $other => 30.0]);
        $pool = $this->courses->popularCoursePool(2);
        $marked = $this->favourites->markFavourites($reader, $pool);
        self::assertTrue($marked[0]['is_favourite']);
        self::assertFalse($marked[1]['is_favourite']);
        self::assertSame($pool, $this->favourites->markFavourites(null, $pool), 'Nobody signed in: the cards are left as they are.');
    }

    private function course(string $label, string $published = '-1 day', string $status = 'published'): int
    {
        $id = $this->fixture->createCourse($this->owner, $this->company, 'pool-' . $label . '-' . $this->suffix, 'Pool ' . $label . ' ' . $this->suffix, $status);
        $this->db->executeStatement('UPDATE courses SET published_at = NOW() + CAST(:offset AS INTERVAL) WHERE id = :id', ['offset' => $published, 'id' => $id]);
        return $id;
    }

    /**
     * Stores a ranking holding only these courses and scores, as a recalculation would.
     *
     * @param array<int,float> $scores
     */
    private function rank(array $scores): void
    {
        $run = (int) $this->db->fetchOne(
            "INSERT INTO course_popularity_runs (calculated_at, window_start, window_end, parameters, course_count)
             VALUES (NOW(), NOW() - INTERVAL '30 days', NOW(), '{}'::jsonb, :count) RETURNING id",
            ['count' => count($scores)]
        );
        arsort($scores);
        $rank = 0;
        foreach ($scores as $course => $score) {
            $this->db->executeStatement(
                "INSERT INTO course_popularity (course_id, run_id, rank, score, view_points, favourite_points, purchase_points, refund_penalty_points, rating_points, momentum_points,
                    offer, unique_views, learner_readers, favourites, purchases, purchased_units, refunds, refunded_units, refund_ratio, approved_reviews, recent_views, previous_views, momentum_growth)
                 VALUES (:course, :run, :rank, :score, :score, 0, 0, 0, 0, 0, 'none', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1)",
                ['course' => $course, 'run' => $run, 'rank' => ++$rank, 'score' => $score]
            );
        }
    }

    /**
     * @param list<array<string,mixed>> $pool
     * @return list<int>
     */
    private static function ids(array $pool): array
    {
        return array_map(static fn(array $course): int => (int) $course['id'], $pool);
    }
}
