<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseService;
use CattoLearning\Course\Popularity\CoursePopularityRepository;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Kernel;
use CattoLearning\Support\Env;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page's Popular Courses through real route dispatch: the pool rendered whole as groups of
 * the shared course card grid with only the first shown, the highest-ranked courses in that first
 * group, the Pause and Resume controls, favourite stars for a signed-in reader, no course views
 * recorded for cards on the home page, and the old showcase fragment route gone.
 */
final class HomePopularCoursesPageTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    /** @var array<string,mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->cookies = $_COOKIE;
        if ((int) $this->db->fetchOne("SELECT COUNT(*) FROM courses WHERE status='published'") < CourseService::POPULAR_GROUP_SIZE + 1) {
            self::markTestSkipped('The development database needs more published courses than one group holds.');
        }
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $this->fixture->cleanup();
    }

    public function testThePoolArrivesAsGroupsOfCourseCardsWithOnlyTheFirstShown(): void
    {
        $html = $this->html('/');
        self::assertStringContainsString('id="popular-courses"', $html);
        self::assertStringContainsString('data-controller="popular-courses"', $html);
        self::assertStringContainsString('data-popular-courses-interval-value="' . CourseService::POPULAR_ROTATION_SECONDS . '"', $html);
        self::assertStringContainsString('>Popular courses</h2>', $html);
        preg_match_all('~<div class="cl-popular-courses-group" role="group" aria-roledescription="slide" aria-label="(\d+) of (\d+)" data-popular-courses-target="group"( hidden)?>~', $html, $groups, PREG_SET_ORDER);
        $poolSize = substr_count($html, '<article class="cl-course-card">');
        self::assertSame(CourseService::POPULAR_POOL_SIZE, $poolSize, 'The whole bounded pool is in the page.');
        self::assertCount((int) ceil($poolSize / CourseService::POPULAR_GROUP_SIZE), $groups);
        foreach ($groups as $i => $group) {
            self::assertSame((string) ($i + 1), $group[1]);
            self::assertSame($i === 0 ? '' : ' hidden', $group[3] ?? '', 'Only the first group is shown without JavaScript.');
        }
        self::assertSame(count($groups), substr_count($html, '<div class="cl-course-cards">'), 'Each group is the shared catalogue course grid.');
        self::assertMatchesRegularExpression('~<button[^>]*hidden[^>]*id="popular-courses-pause"[^>]*data-action="popular-courses#pause"~', $html, 'Pause is a real button, revealed by the controller.');
        self::assertMatchesRegularExpression('~<button[^>]*hidden[^>]*id="popular-courses-resume"[^>]*data-action="popular-courses#resume"~', $html);
        self::assertStringNotContainsString('class="cl-favourite-form"', $html, 'A visitor has no favourite stars.');
    }

    public function testTheFirstGroupHoldsTheHighestRankedCourses(): void
    {
        $top = IntegrationContainer::get()->get(CoursePopularityRepository::class)->popularCourses(CourseService::POPULAR_GROUP_SIZE);
        if (count($top) < CourseService::POPULAR_GROUP_SIZE) {
            self::markTestSkipped('Popularity has not been calculated for enough courses.');
        }
        $html = $this->html('/');
        $first = substr($html, (int) strpos($html, 'aria-label="1 of '), (int) strpos($html, 'aria-label="2 of ') - (int) strpos($html, 'aria-label="1 of '));
        $positions = array_map(static fn(array $course): int|false => strpos($first, 'href="/courses/' . $course['slug'] . '"'), $top);
        self::assertNotContains(false, $positions, 'The four best-ranked courses are the first group.');
        $sorted = $positions; sort($sorted);
        self::assertSame($sorted, $positions, 'In rank order.');
    }

    public function testASignedInReaderSeesTheirFavouriteStars(): void
    {
        $suffix = $this->fixture->suffix();
        $reader = $this->fixture->createUser('Home reader ' . $suffix, 'home-reader-' . $suffix . '@seed.test');
        $this->fixture->grantRole($reader, 'STUDENT');
        $this->signIn($reader);
        $html = $this->html('/');
        preg_match('~id="favourite-(\d+)"~', $html, $match);
        self::assertNotEmpty($match, 'The cards carry the favourite star for a signed-in reader.');
        $course = (int) $match[1];
        self::assertMatchesRegularExpression('~id="favourite-' . $course . '">.*?name="return" value="/"~s', $html, 'Without JavaScript the star returns to the home page.');

        $this->db->executeStatement('INSERT INTO course_favourites (user_id, course_id) VALUES (:u, :c)', ['u' => $reader, 'c' => $course]);
        self::assertMatchesRegularExpression('~id="favourite-' . $course . '">.*?aria-pressed="true"~s', $this->html('/'), 'A favourited course shows its star on.');
    }

    public function testCardsOnTheHomePageAreNotCourseViews(): void
    {
        $before = (int) $this->db->fetchOne("SELECT COUNT(*) FROM analytics_events WHERE event_type='course_view'");
        $this->html('/');
        self::assertSame($before, (int) $this->db->fetchOne("SELECT COUNT(*) FROM analytics_events WHERE event_type='course_view'"));
    }

    public function testTheShowcaseFragmentRouteIsGone(): void
    {
        self::assertNotSame(200, $this->send('/courses/showcase?cycle=1')->getStatusCode());
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
