<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseFavouriteService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\DevelopmentFixture;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The analytics event stream: what the recorder accepts and refuses, idempotency, anonymous and
 * signed-in events, the aggregate queries later features build on, retention, the schema's
 * indexes, and favourites recording only real changes.
 */
final class AnalyticsEventIntegrationTest extends TestCase
{
    private Database $db;
    private \Psr\Container\ContainerInterface $container;
    private AnalyticsEventRepository $events;
    private AnalyticsEventRecorder $recorder;
    private MockClock $clock;
    private DevelopmentFixture $fixture;
    private int $user;
    private int $otherUser;
    private int $course;
    private int $otherCourse;

    protected function setUp(): void
    {
        $this->container = $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->db->beginTransaction();
        $this->clock = new MockClock('2026-10-04T10:00:00+00:00');
        $this->events = new AnalyticsEventRepository($this->db);
        $this->recorder = new AnalyticsEventRecorder($this->events, $this->clock);
        $this->fixture = new DevelopmentFixture($this->db);
        $this->user = $this->fixture->createUser('Analytics learner');
        $this->otherUser = $this->fixture->createUser('Analytics other');
        $company = $this->fixture->createCompany($this->user, 'Analytics ' . $this->fixture->suffix(), $this->fixture->suffix() . '.example.test');
        $this->course = $this->fixture->createCourse($this->user, $company, 'analytics-' . $this->fixture->suffix(), 'Analytics course', 'published');
        $this->otherCourse = $this->fixture->createCourse($this->user, $company, 'analytics-other-' . $this->fixture->suffix(), 'Analytics other course', 'published');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    public function testAValidEventIsRecordedWithTheClockTimeAndItsReferences(): void
    {
        $visitor = Uuid::v4();
        self::assertTrue($this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course, 'user_id' => $this->user, 'visitor_id' => $visitor], ['context' => 'course_page']));
        $row = $this->latest();
        self::assertSame('course_view', $row['event_type']);
        self::assertSame('course_detail', $row['source']);
        self::assertSame($this->user, (int) $row['user_id'], 'A signed-in viewer is associated by id.');
        self::assertSame($visitor, $row['visitor_id']);
        self::assertSame($this->course, (int) $row['course_id']);
        self::assertSame(['context' => 'course_page'], json_decode((string) $row['metadata'], true));
        self::assertSame('2026-10-04 10:00:00', (new DateTimeImmutable((string) $row['occurred_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertNull($row['idempotency_key']);
    }

    public function testAnAnonymousVisitorIsRecordedWithoutAPerson(): void
    {
        $visitor = Uuid::v4();
        $this->recorder->record('course_view', AnalyticsSource::PublicPreview, ['course_id' => $this->course, 'visitor_id' => $visitor], ['context' => 'course_page']);
        $row = $this->latest();
        self::assertNull($row['user_id']);
        self::assertSame($visitor, $row['visitor_id']);
    }

    public function testInvalidEventsAreRefused(): void
    {
        $cases = [
            'unknown type' => fn() => $this->recorder->record('course_liked', AnalyticsSource::CourseDetail, ['course_id' => $this->course]),
            'missing course' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['user_id' => $this->user]),
            'favourite without person' => fn() => $this->recorder->record(AnalyticsEventType::CourseFavouriteAdded, AnalyticsSource::Catalogue, ['course_id' => $this->course]),
            'unknown reference' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course, 'session_id' => 'abc']),
            'non-uuid visitor' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course, 'visitor_id' => 'fingerprint-123']),
            'string id' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => (string) $this->course]), // @phpstan-ignore argument.type (a string id is the point of the case)
            'key outside the schema' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course], ['referrer' => 'x']),
            'wrong metadata type' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course], ['node_id' => '7']),
            'over-long string' => fn() => $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $this->course], ['context' => str_repeat('x', 65)]),
            'purchase without a key' => fn() => $this->recorder->record(AnalyticsEventType::CoursePurchased, AnalyticsSource::Checkout, ['course_id' => $this->course, 'order_id' => 1, 'order_item_id' => 1]),
        ];
        foreach ($cases as $label => $case) {
            try {
                $case();
                self::fail('Accepted: ' . $label);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->recorded());
    }

    public function testSensitiveMetadataCannotBeRecordedForAnyEvent(): void
    {
        foreach (['card_number', 'cvv', 'password', 'auth_token', 'billing_address', 'email', 'ip_address', 'user_agent_header', 'session'] as $key) {
            foreach (AnalyticsEventType::cases() as $type) {
                try {
                    $this->recorder->record($type, AnalyticsSource::Checkout, ['user_id' => $this->user, 'course_id' => $this->course], [$key => 'x'], 'sensitive:' . $type->value . ':' . $key);
                    self::fail($type->value . ' accepted ' . $key);
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
            foreach (AnalyticsEventType::cases() as $type) {
                self::assertArrayNotHasKey($key, $type->metadataSchema(), 'No event type declares ' . $key . '.');
            }
        }
        self::assertSame(0, $this->recorded());
    }

    public function testAnIdempotencyKeyRecordsOneLogicalEvent(): void
    {
        $references = ['user_id' => $this->user];
        self::assertTrue($this->recorder->record(AnalyticsEventType::CheckoutStarted, AnalyticsSource::Checkout, $references, ['cart_id' => 7, 'line_count' => 1], 'checkout_started:cart:test-' . $this->user));
        self::assertFalse($this->recorder->record(AnalyticsEventType::CheckoutStarted, AnalyticsSource::Checkout, $references, ['cart_id' => 7, 'line_count' => 1], 'checkout_started:cart:test-' . $this->user), 'A replay is ignored.');
        self::assertSame(1, $this->recorded());
    }

    public function testAggregatesByCourseTypeAndDayOverAPeriod(): void
    {
        $since = new DateTimeImmutable('2026-09-04T10:00:00+00:00');
        $this->at('2026-08-01T09:00:00+00:00', fn() => $this->view($this->course, $this->user, Uuid::v4()));
        $visitorA = Uuid::v4(); $visitorB = Uuid::v4();
        $this->at('2026-10-02T09:00:00+00:00', function () use ($visitorA, $visitorB): void {
            $this->view($this->course, $this->user, Uuid::v4());
            $this->view($this->course, $this->user, Uuid::v4());
            $this->view($this->course, $this->otherUser, Uuid::v4());
            $this->view($this->course, null, $visitorA);
            $this->view($this->course, null, $visitorA);
            $this->view($this->course, null, $visitorB);
            $this->view($this->otherCourse, null, $visitorA);
        });
        $this->at('2026-10-03T09:00:00+00:00', fn() => $this->recorder->record(AnalyticsEventType::CourseFavouriteAdded, AnalyticsSource::Catalogue, ['user_id' => $this->user, 'course_id' => $this->course]));

        self::assertSame(6, $this->events->countEvents(AnalyticsEventType::CourseView, $since, $this->course), 'Raw views in the period; the older view is outside it.');
        self::assertSame(['users' => 2, 'anonymous_visitors' => 2, 'total' => 4], $this->events->uniqueViewers($this->course, $since), 'Unique viewers are not raw views.');
        self::assertSame(1, $this->events->countEvents(AnalyticsEventType::CourseFavouriteAdded, $since, $this->course));
        self::assertSame([['course_id' => $this->course, 'events' => 6], ['course_id' => $this->otherCourse, 'events' => 1]], array_values(array_filter($this->events->countsByCourse(AnalyticsEventType::CourseView, $since), fn(array $row): bool => in_array($row['course_id'], [$this->course, $this->otherCourse], true))));
        $days = array_values(array_filter($this->events->countsByTypeAndDay($since), static fn(array $row): bool => in_array($row['day'], ['2026-10-02', '2026-10-03'], true)));
        self::assertContains(['day' => '2026-10-02', 'event_type' => 'course_view', 'events' => 7], $days);
        self::assertContains(['day' => '2026-10-03', 'event_type' => 'course_favourite_added', 'events' => 1], $days);
        $this->db->executeStatement('INSERT INTO course_favourites (user_id,course_id,created_at) VALUES (:u,:c,NOW()),(:o,:c,NOW())', ['u' => $this->user, 'o' => $this->otherUser, 'c' => $this->course]);
        self::assertSame(2, $this->events->currentFavouriteCount($this->course), 'The favourites table is the current count.');
    }

    public function testRetentionAnonymisesOrRemovesOldEventsAndDeletionKeepsFacts(): void
    {
        $this->at('2026-01-01T00:00:00+00:00', fn() => $this->view($this->course, $this->user, Uuid::v4()));
        $this->view($this->course, $this->user, Uuid::v4());
        $before = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        self::assertGreaterThanOrEqual(1, $this->events->anonymiseBefore($before));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE course_id=:c AND occurred_at < :b AND (user_id IS NOT NULL OR visitor_id IS NOT NULL)', ['c' => $this->course, 'b' => '2026-06-01 00:00:00+00']));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE course_id=:c AND user_id IS NOT NULL', ['c' => $this->course]), 'Recent events keep their person.');
        $this->db->executeStatement('DELETE FROM user_emails WHERE user_id=:id', ['id' => $this->otherUser]);
        $this->view($this->course, $this->otherUser, null);
        $this->db->executeStatement('DELETE FROM users WHERE id=:id', ['id' => $this->otherUser]);
        self::assertSame(3, $this->recorded(), 'Removing a person keeps the facts.');
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE user_id=:id', ['id' => $this->otherUser]));
        self::assertGreaterThanOrEqual(1, $this->events->deleteBefore($before));
        self::assertSame(2, $this->recorded());
    }

    public function testTheSchemaHasItsIndexesAndConstraints(): void
    {
        $indexes = array_column($this->db->fetchAllAssociative("SELECT indexname, indexdef FROM pg_indexes WHERE tablename='analytics_events'"), 'indexdef', 'indexname');
        self::assertStringContainsString('(course_id, event_type, occurred_at)', $indexes['analytics_events_course_type_time_idx'] ?? '');
        self::assertStringContainsString('(event_type, occurred_at)', $indexes['analytics_events_type_time_idx'] ?? '');
        self::assertStringContainsString('(occurred_at)', $indexes['analytics_events_time_idx'] ?? '');
        self::assertStringContainsString('(user_id)', $indexes['analytics_events_user_idx'] ?? '');
        self::assertNotEmpty(array_filter($indexes, static fn(string $definition): bool => str_contains($definition, 'UNIQUE') && str_contains($definition, '(idempotency_key)')), 'Idempotency keys are unique.');
        $columns = array_column($this->db->fetchAllAssociative("SELECT column_name, data_type FROM information_schema.columns WHERE table_name='analytics_events'"), 'data_type', 'column_name');
        self::assertSame('jsonb', $columns['metadata']);
        self::assertSame('uuid', $columns['visitor_id']);
        self::assertArrayNotHasKey('ip_address', $columns, 'No IP addresses.');
        try {
            $this->db->executeStatement("INSERT INTO analytics_events (event_type,source,occurred_at,metadata) VALUES ('Bad Type','catalogue',NOW(),'{}')");
            self::fail('A malformed event type was stored.');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    public function testFavouritesRecordOnlyRealChanges(): void
    {
        $favourites = $this->container->get(CourseFavouriteService::class);
        self::assertTrue($favourites->set($this->user, $this->course, true, AnalyticsSource::Catalogue));
        self::assertFalse($favourites->set($this->user, $this->course, true, AnalyticsSource::Catalogue), 'Asking again changes nothing.');
        self::assertSame(1, $this->typeCount('course_favourite_added'));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_favourites WHERE user_id=:u AND course_id=:c', ['u' => $this->user, 'c' => $this->course]), 'The favourites table holds the state.');
        self::assertTrue($favourites->set($this->user, $this->course, false, AnalyticsSource::CourseDetail));
        self::assertFalse($favourites->set($this->user, $this->course, false, AnalyticsSource::CourseDetail));
        self::assertSame(1, $this->typeCount('course_favourite_removed'));
        self::assertFalse($favourites->set($this->user, $this->otherCourse, false, AnalyticsSource::Catalogue), 'Removing a favourite that was never there is not an event.');
        self::assertSame(2, $this->recorded());
        $removed = $this->latest();
        self::assertSame(['course_detail', $this->user, $this->course], [$removed['source'], (int) $removed['user_id'], (int) $removed['course_id']]);
    }

    private function view(int $course, ?int $user, ?string $visitor): void
    {
        $this->recorder->record(AnalyticsEventType::CourseView, AnalyticsSource::CourseDetail, ['course_id' => $course, 'user_id' => $user, 'visitor_id' => $visitor], ['context' => 'course_page']);
    }

    private function at(string $moment, callable $record): void
    {
        $this->clock->modify($moment);
        $record();
        $this->clock->modify('2026-10-04T10:00:00+00:00');
    }

    /** @return array<string,mixed> */
    private function latest(): array
    {
        $row = $this->db->fetchAssociative('SELECT *, metadata::text AS metadata FROM analytics_events WHERE course_id IN (:a,:b) OR user_id IN (:u,:o) ORDER BY id DESC LIMIT 1', ['a' => $this->course, 'b' => $this->otherCourse, 'u' => $this->user, 'o' => $this->otherUser]);
        self::assertIsArray($row);
        return $row;
    }

    private function recorded(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE course_id IN (:a,:b) OR user_id IN (:u,:o)', ['a' => $this->course, 'b' => $this->otherCourse, 'u' => $this->user, 'o' => $this->otherUser]);
    }

    private function typeCount(string $type): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE event_type=:t AND (course_id IN (:a,:b) OR user_id IN (:u,:o))', ['t' => $type, 'a' => $this->course, 'b' => $this->otherCourse, 'u' => $this->user, 'o' => $this->otherUser]);
    }
}
