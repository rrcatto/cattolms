<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Kernel;
use CattoLearning\Support\Env;
use CattoLearning\Support\Token;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Course reviews through real route dispatch: the learner's review page and its accessible star
 * control, who may post a review, the course page showing only approved reviews and the rating
 * from them, the ADMIN moderation page and its decisions, and the Administration menu entry.
 */
final class CourseReviewPageTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private string $slug;
    private string $email;
    private int $course;
    private int $learner;
    private int $outsider;
    private int $admin;
    /** @var array<string,mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $suffix = $this->fixture->suffix();
        $this->slug = 'review-page-' . $suffix;
        $this->admin = $this->fixture->createUser('Review admin ' . $suffix, 'review-admin-' . $suffix . '@seed.test');
        $this->fixture->grantRole($this->admin, 'ADMIN');
        $company = $this->fixture->createCompany($this->admin, 'Review page ' . $suffix, 'review-page-' . $suffix . '.seed.test');
        $this->course = $this->fixture->createCourse($this->admin, $company, $this->slug, 'Review page ' . $suffix);
        $this->email = 'review-learner-' . $suffix . '@seed.test';
        $this->learner = $this->fixture->createUser('Jane Doe', $this->email);
        $this->db->executeStatement("UPDATE users SET first_name='Jane', last_name='Doe' WHERE id=:id", ['id' => $this->learner]);
        $this->fixture->grantRole($this->learner, 'STUDENT');
        $this->db->executeStatement(
            "INSERT INTO course_enrolments (public_id,user_id,course_id,status,access_period_seconds,assigned_by_user_id,is_preview) VALUES (:public_id,:user,:course,'assigned',31536000,:admin,FALSE)",
            ['public_id' => Uuid::v4(), 'user' => $this->learner, 'course' => $this->course, 'admin' => $this->admin]
        );
        $this->outsider = $this->fixture->createUser('No access ' . $suffix, 'review-outsider-' . $suffix . '@seed.test');
        $this->fixture->grantRole($this->outsider, 'STUDENT');
        $this->cookies = $_COOKIE;
        $_SESSION['csrf'] = str_repeat('c', 64);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $this->db->executeStatement('DELETE FROM course_reviews WHERE course_id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM course_enrolments WHERE course_id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM analytics_events WHERE course_id=:id', ['id' => $this->course]);
        $this->fixture->cleanup();
    }

    public function testTheReviewFormIsAnAccessibleFiveStarControlWithAnOptionalComment(): void
    {
        $this->signIn($this->learner);
        $html = $this->html('/learn/' . $this->slug . '/review');
        self::assertStringContainsString('<fieldset class="cl-rating-input">', $html);
        self::assertStringContainsString('Your rating', $html);
        self::assertSame(5, substr_count($html, 'type="radio" name="rating"'), 'Five real radio buttons.');
        foreach (['1 star<', '2 stars<', '3 stars<', '4 stars<', '5 stars<'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('name="comment"', $html);
        self::assertStringContainsString('Submit review', $html);
        self::assertStringContainsString('Rate this course', $this->html('/courses/' . $this->slug), 'The course page offers an eligible learner the review page.');
        self::assertStringContainsString('/learn/' . $this->slug . '/review', $this->html('/learn/' . $this->slug), 'So does the learner course home.');
    }

    public function testOnlyAnEntitledSignedInLearnerCanPostAReview(): void
    {
        $_COOKIE = $this->cookies;
        self::assertNotSame(200, $this->send('/learn/' . $this->slug . '/review')->getStatusCode(), 'A visitor who is not signed in gets no form.');
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '5', 'comment' => 'Anonymous']);

        $this->signIn($this->outsider);
        self::assertSame(403, $this->send('/learn/' . $this->slug . '/review')->getStatusCode(), 'A learner without access is refused.');
        self::assertStringNotContainsString('Rate this course', $this->html('/courses/' . $this->slug));
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '5', 'comment' => 'No access']);
        self::assertSame(404, $this->send('/learn/no-such-course-' . $this->fixture->suffix() . '/review')->getStatusCode());
        self::assertSame(0, $this->reviewCount(), 'Neither request stored a review.');

        $this->signIn($this->learner);
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '0', 'comment' => 'Zero']);
        self::assertSame(0, $this->reviewCount(), 'An invalid rating is refused.');
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '4', 'comment' => 'Worth it.']);
        self::assertSame(1, $this->reviewCount());
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '5', 'comment' => 'Even better.']);
        self::assertSame(1, $this->reviewCount(), 'A second post edits the same review.');
        $page = $this->html('/learn/' . $this->slug . '/review');
        self::assertStringContainsString('Pending moderation', $page);
        self::assertStringContainsString('Even better.', $page, 'The form shows the learner their review to edit.');
        self::assertStringContainsString('value="5" checked', $page);
        self::assertStringContainsString('Update review', $page);
    }

    public function testTheCoursePageShowsOnlyApprovedReviewsAndTheirRating(): void
    {
        $this->signIn($this->learner);
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '4', 'comment' => 'Thorough and well paced.']);
        $_COOKIE = $this->cookies;
        $pending = $this->html('/courses/' . $this->slug);
        self::assertStringNotContainsString('Thorough and well paced.', $pending, 'A pending review is not public.');
        self::assertStringContainsString('No reviews yet', $pending);

        $this->signIn($this->admin);
        self::assertStringContainsString('Course Reviews (1 pending)', $this->html('/admin'), 'The Administration menu shows the queue.');
        $queue = $this->html('/admin/course-reviews');
        self::assertStringContainsString('Thorough and well paced.', $queue);
        self::assertStringContainsString('Approve', $queue);
        $review = $this->db->fetchAssociative('SELECT id, revision FROM course_reviews WHERE course_id=:c', ['c' => $this->course]) ?: [];
        $this->post('/admin/course-reviews/' . $review['id'] . '/moderate', ['decision' => 'approve', 'revision' => (string) $review['revision'], 'note' => 'Moderator-only remark', 'return' => '/admin/course-reviews']);
        self::assertSame('approved', $this->db->fetchOne('SELECT status FROM course_reviews WHERE id=:id', ['id' => $review['id']]));
        self::assertStringContainsString('No reviews are waiting', $this->html('/admin/course-reviews'));
        self::assertStringContainsString('Thorough and well paced.', $this->html('/admin/course-reviews?status=approved&rating=4&course_id=' . $this->course), 'The filters find it.');
        self::assertStringNotContainsString('Thorough and well paced.', $this->html('/admin/course-reviews?status=approved&rating=1'));

        $_COOKIE = $this->cookies;
        $public = $this->html('/courses/' . $this->slug);
        self::assertStringContainsString('Thorough and well paced.', $public);
        self::assertStringContainsString('Jane D.', $public, 'Published under the first name and initial.');
        self::assertStringContainsString('Rated 4.0 out of 5 from 1 review', $public);
        self::assertStringNotContainsString($this->email, $public);
        self::assertStringNotContainsString('Moderator-only remark', $public);
        self::assertStringNotContainsString('Jane Doe', $public, 'The full name is not published.');

        $this->signIn($this->admin);
        $this->post('/admin/course-reviews/' . $review['id'] . '/moderate', ['decision' => 'reject', 'revision' => (string) $review['revision'], 'return' => '/admin/course-reviews?status=approved']);
        $_COOKIE = $this->cookies;
        self::assertStringNotContainsString('Thorough and well paced.', $this->html('/courses/' . $this->slug), 'A rejected review is taken down.');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM analytics_events WHERE course_id=:c AND event_type='review_rejected'", ['c' => $this->course]));
    }

    public function testALearnerCannotModerate(): void
    {
        $this->signIn($this->learner);
        $this->post('/learn/' . $this->slug . '/review', ['rating' => '3', 'comment' => '']);
        self::assertNotSame(200, $this->send('/admin/course-reviews')->getStatusCode());
        $review = $this->db->fetchAssociative('SELECT id, revision FROM course_reviews WHERE course_id=:c', ['c' => $this->course]) ?: [];
        $this->post('/admin/course-reviews/' . $review['id'] . '/moderate', ['decision' => 'approve', 'revision' => (string) $review['revision']]);
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM course_reviews WHERE id=:id', ['id' => $review['id']]));
    }

    private function signIn(int $user): void
    {
        $token = Token::generate();
        IntegrationContainer::get()->get(AuthSessionRepository::class)->create($user, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $_COOKIE = $this->cookies;
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
    }

    private function reviewCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_reviews WHERE course_id=:c', ['c' => $this->course]);
    }

    /** @param array<string,string> $fields */
    private function post(string $url, array $fields): void
    {
        $_POST = ['csrf' => $_SESSION['csrf']] + $fields;
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            $kernel->handle(Request::create($url, 'POST', $_POST));
        } finally {
            $kernel->shutdown();
            $_POST = [];
        }
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
