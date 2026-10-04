<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\Popularity\CoursePopularityCalculator;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Kernel;
use CattoLearning\Support\Env;
use CattoLearning\Support\Token;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ADMIN course popularity report through real route dispatch: the ranked table with its
 * metrics, filtering, sorting and pagination, the per-course breakdown, the link from Reports,
 * and that it is ADMIN-only.
 */
final class CoursePopularityPageTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private string $suffix;
    private int $admin;
    private int $learner;
    private int $leader;
    private int $free;
    /** @var array<string,mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->admin = $this->fixture->createUser('Popularity admin ' . $this->suffix, 'popularity-admin-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($this->admin, 'ADMIN');
        $this->learner = $this->fixture->createUser('Popularity learner ' . $this->suffix, 'popularity-learner-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($this->learner, 'STUDENT');
        $company = $this->fixture->createCompany($this->admin, 'Popularity page ' . $this->suffix, 'popularity-' . $this->suffix . '.seed.test');
        $this->leader = $this->fixture->createCourse($this->admin, $company, 'popularity-leader-' . $this->suffix, 'Popular leader ' . $this->suffix);
        $this->free = $this->fixture->createCourse($this->admin, $company, 'popularity-free-' . $this->suffix, 'Popular free ' . $this->suffix);
        $this->db->executeStatement(
            "INSERT INTO course_price_variants (public_id,course_id,access_period_seconds,price_minor_units,currency_code,label,position,is_active,is_default,created_by_user_id,updated_by_user_id)
             VALUES (:a,:paid,2592000,25000,'ZAR','QA',1,TRUE,TRUE,:u,:u), (:b,:free,2592000,0,'ZAR','QA',1,TRUE,TRUE,:u,:u)",
            ['a' => Uuid::v4(), 'b' => Uuid::v4(), 'paid' => $this->leader, 'free' => $this->free, 'u' => $this->admin]
        );
        $events = IntegrationContainer::get()->get(AnalyticsEventRepository::class);
        $now = new DateTimeImmutable();
        for ($i = 0; $i < 12; $i++) { $events->append('course_view', 'course_detail', $now->modify('-2 days'), ['course_id' => $this->leader, 'visitor_id' => Uuid::v4()], [], null); }
        for ($i = 0; $i < 3; $i++) { $events->append('course_view', 'public_preview', $now->modify('-2 days'), ['course_id' => $this->free, 'visitor_id' => Uuid::v4()], [], null); }
        $events->append('course_purchased', 'checkout', $now->modify('-1 day'), ['course_id' => $this->leader], ['quantity' => 1], 'course_purchased:test:' . Uuid::v4());
        IntegrationContainer::get()->get(CoursePopularityCalculator::class)->recalculate();
        $this->cookies = $_COOKIE;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $this->db->executeStatement('DELETE FROM analytics_events WHERE course_id IN (:a, :b)', ['a' => $this->leader, 'b' => $this->free]);
        $this->fixture->cleanup();
    }

    public function testTheReportRanksCoursesWithTheirMetricsAndExplainsTheModel(): void
    {
        $this->signIn($this->admin);
        $html = $this->html('/admin/reports/popularity?popularity_q=' . $this->suffix);
        foreach (['Course popularity', 'How the score is calculated', 'Calculated', 'Window', '30 days', 'Rating prior', 'Unique views', 'Favourites', 'Purchases', 'Refunds', 'Rating', 'Reviews', 'Momentum', 'Purchases · 35 points'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertLessThan(strpos($html, 'Popular free ' . $this->suffix), strpos($html, 'Popular leader ' . $this->suffix), 'The leader is listed first.');
        self::assertStringContainsString('href="/admin/reports/popularity/' . $this->leader . '"', $html);
        self::assertMatchesRegularExpression('~Popular leader ' . $this->suffix . '</strong></a></td>\s*<td><strong>[0-9.]+</strong></td>\s*<td>12</td>~', $html, 'The score and twelve distinct viewers are shown.');
        self::assertStringContainsString('Free', $html, 'A free course is labelled.');
    }

    public function testTheReportFiltersSortsAndPaginates(): void
    {
        $this->signIn($this->admin);
        $free = $this->html('/admin/reports/popularity?offer=free&popularity_q=' . $this->suffix);
        self::assertStringContainsString('Popular free ' . $this->suffix, $free);
        self::assertStringNotContainsString('Popular leader ' . $this->suffix, $free);
        self::assertStringContainsString('href="/admin/reports/popularity?popularity_q=' . $this->suffix . '&amp;popularity_sort=rank&amp;popularity_dir=asc&amp;offer=paid"', $free, 'Each offer chip keeps the search and sort.');
        self::assertStringContainsString('name="popularity_q"', $free, 'The shared search control is used.');

        $ascending = $this->html('/admin/reports/popularity?popularity_sort=views&popularity_dir=asc&popularity_q=' . $this->suffix);
        self::assertLessThan(strpos($ascending, 'Popular leader ' . $this->suffix), strpos($ascending, 'Popular free ' . $this->suffix), 'Fewest views first.');
        self::assertStringContainsString('aria-sort="ascending"', $ascending);
        self::assertStringContainsString('popularity_sort=views&amp;popularity_dir=desc', $ascending, 'The header offers the other direction, keeping the search.');

        $all = $this->html('/admin/reports/popularity');
        self::assertStringContainsString('popularity_page=2', $all, 'Every published course is ranked, so the report pages.');
        self::assertStringContainsString('href="/admin/reports"', $all);
    }

    public function testTheBreakdownShowsEveryComponent(): void
    {
        $this->signIn($this->admin);
        $html = $this->html('/admin/reports/popularity/' . $this->leader);
        foreach (['Score components', 'View score', 'Favourite score', 'Purchase score', 'Refund penalty', 'Rating score', 'Momentum score', 'Final score',
            'Source metrics', 'Unique discovery viewers', '12 (course page and public preview)', '1 paid order line, 1 unit', 'No approved reviews', 'Learners in the reader'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertSame(404, $this->send('/admin/reports/popularity/999999999')->getStatusCode());
    }

    public function testReportsLinksToItAndOnlyReportViewersMayOpenIt(): void
    {
        $this->signIn($this->admin);
        self::assertStringContainsString('href="/admin/reports/popularity"', $this->html('/admin/reports'));
        $this->signIn($this->learner);
        self::assertNotSame(200, $this->send('/admin/reports/popularity')->getStatusCode());
        self::assertNotSame(200, $this->send('/admin/reports/popularity/' . $this->leader)->getStatusCode());
    }

    private function signIn(int $user): void
    {
        $token = Token::generate();
        IntegrationContainer::get()->get(AuthSessionRepository::class)->create($user, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $_COOKIE = $this->cookies;
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
    }

    private function send(string $url): Response
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        $_GET = Request::create($url)->query->all();
        try {
            return $kernel->handle(Request::create($url, 'GET'));
        } finally {
            $kernel->shutdown();
            $_GET = [];
        }
    }

    private function html(string $url): string
    {
        $response = $this->send($url);
        self::assertSame(200, $response->getStatusCode(), $url . ' must render: ' . substr(strip_tags((string) $response->getContent()), 0, 400));
        return (string) $response->getContent();
    }
}
