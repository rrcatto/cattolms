<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Http\Controller\AdminCourseComponentController;
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
 * Editing a shared Course Item returns to where editing began: Course Content of the course being
 * edited (at the row), or the Course Item Library. The return path is carried as `return_to`, kept
 * through re-displays and refused saves, and only known ADMIN destinations are honoured.
 */
final class CourseItemEditReturnTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private CourseItemService $items;
    private string $suffix;
    private int $owner;
    private int $course;
    private int $otherCourse;
    private int $itemId;
    private int $node;
    private string $returnTo;
    /** @var array<string,mixed> */
    private array $cookies = [];
    /** @var array<string,mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $container = IntegrationContainer::get();
        $this->db = IntegrationContainer::db();
        $this->items = $container->get(CourseItemService::class);
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->owner = $this->fixture->createUser('Item editor ' . $this->suffix, 'item-editor-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($this->owner, 'ADMIN');
        $this->fixture->attachToSystemCompany($this->owner);
        $company = $this->fixture->createCompany($this->owner, 'Item editor ' . $this->suffix, 'item-editor-' . $this->suffix . '.seed.test');
        $this->course = $this->fixture->createCourse($this->owner, $company, 'item-editor-' . $this->suffix, 'Item editor ' . $this->suffix);
        $this->otherCourse = $this->fixture->createCourse($this->owner, $company, 'item-editor-other-' . $this->suffix, 'Item editor other ' . $this->suffix);
        $this->itemId = $this->items->create(['item_key' => 'shared-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Shared lesson', 'content_source' => '<p>Body</p>'], $this->owner);
        $this->node = $this->items->addExisting($this->course, $this->itemId, [], $this->owner);
        $this->items->addExisting($this->otherCourse, $this->itemId, [], $this->owner);
        $this->returnTo = '/admin/courses/' . $this->course . '/content#course-node-' . $this->node;

        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($this->owner, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $this->cookies = $_COOKIE;
        $this->session = $_SESSION ?? [];
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
        $_SESSION['csrf'] = str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $_SESSION = $this->session;
        $this->db->executeStatement('DELETE FROM courses WHERE owner_user_id=:owner', ['owner' => $this->owner]);
        $this->db->executeStatement('DELETE FROM course_items WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
        $this->fixture->cleanup();
    }

    public function testCourseContentOpensTheEditorWithItsOwnReturnPath(): void
    {
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        $link = '/admin/course-items/' . $this->itemId . '?return_to=' . rawurlencode($this->returnTo);
        self::assertStringContainsString('href="' . htmlspecialchars($link, ENT_QUOTES) . '"', $html, 'Edit shared item carries the course and the row.');
        self::assertStringNotContainsString('href="/admin/course-items/' . $this->itemId . '"', $html, 'No Edit shared item link in Course Content loses its context.');
    }

    public function testEditingFromCourseContentReturnsToThatCourse(): void
    {
        $form = $this->ok('/admin/course-items/' . $this->itemId . '?return_to=' . rawurlencode($this->returnTo));
        self::assertStringContainsString('<input type="hidden" name="return_to" value="' . htmlspecialchars($this->returnTo, ENT_QUOTES) . '">', $form, 'The form carries the return path.');
        self::assertSame(2, substr_count($form, 'href="' . htmlspecialchars($this->returnTo, ENT_QUOTES) . '"'), 'Back and Cancel both return to the course.');
        self::assertStringContainsString('Course Content', $form);

        $saved = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['title' => 'Edited in the course', 'return_to' => $this->returnTo]));
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame($this->returnTo, $saved->headers->get('Location'), 'Save returns to the row in Course Content.');
        foreach ([$this->course, $this->otherCourse] as $course) {
            self::assertSame('Edited in the course', $this->db->fetchOne('SELECT ci.title FROM course_item_placements p JOIN course_items ci ON ci.id=p.course_item_id WHERE p.course_id=:course', ['course' => $course]), 'The shared item changes in every course that uses it.');
        }
    }

    public function testARefusedSaveAndARedisplayKeepTheReturnPath(): void
    {
        $refused = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['title' => '', 'content_source' => '<p>Kept body</p>', 'return_to' => $this->returnTo]));
        self::assertSame(422, $refused->getStatusCode(), 'A refused save shows the form again.');
        $html = (string) $refused->getContent();
        self::assertStringContainsString('name="return_to" value="' . htmlspecialchars($this->returnTo, ENT_QUOTES) . '"', $html);
        self::assertStringContainsString('&lt;p&gt;Kept body&lt;/p&gt;', $html, 'What was entered is kept.');
        self::assertStringContainsString('href="' . htmlspecialchars($this->returnTo, ENT_QUOTES) . '"', $html, 'Cancel still returns to the course.');
        self::assertSame('Shared lesson', $this->db->fetchOne('SELECT title FROM course_items WHERE id=:id', ['id' => $this->itemId]), 'Nothing was saved.');

        $redisplayed = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['question_action' => 'add-question', 'return_to' => $this->returnTo]));
        self::assertStringContainsString('name="return_to" value="' . htmlspecialchars($this->returnTo, ENT_QUOTES) . '"', (string) $redisplayed->getContent(), 'A form re-display keeps it too.');

        $copy = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['item_action' => 'save_as', 'copy_key' => 'copy-' . $this->suffix, 'copy_title' => 'Copy', 'return_to' => $this->returnTo]));
        self::assertSame(303, $copy->getStatusCode());
        self::assertMatchesRegularExpression('~^/admin/course-items/\d+\?return_to=' . preg_quote(rawurlencode($this->returnTo), '~') . '$~', (string) $copy->headers->get('Location'), 'Save As opens the copy with the same way back.');
    }

    public function testEditingFromTheLibraryKeepsTheLibraryDefaults(): void
    {
        $form = $this->ok('/admin/course-items/' . $this->itemId);
        self::assertStringNotContainsString('name="return_to"', $form);
        self::assertStringContainsString('href="/admin/course-items"', $form, 'Back and Cancel go to the Course Item Library.');
        $saved = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['title' => 'Edited in the library']));
        self::assertSame('/admin/course-items/' . $this->itemId, $saved->headers->get('Location'), 'Without a return path the existing destination applies.');
        $fromLibrary = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['title' => 'Edited again', 'return_to' => '/admin/course-items']));
        self::assertSame('/admin/course-items', $fromLibrary->headers->get('Location'));
    }

    public function testUnsafeReturnPathsAreIgnored(): void
    {
        foreach (['https://evil.example/admin/courses/1/content', '//evil.example/admin/course-items', '/\\evil.example', 'javascript:alert(1)', "/admin/course-items\n", '/admin/courses/1/content/../../people', '/admin/courses/1/content?next=//evil', '/admin/people', '/admin/courses/0/content', ' /admin/course-items'] as $unsafe) {
            $form = $this->ok('/admin/course-items/' . $this->itemId . '?return_to=' . rawurlencode($unsafe));
            self::assertStringNotContainsString('name="return_to"', $form, 'Not carried: ' . $unsafe);
            $saved = $this->post('/admin/course-items/' . $this->itemId, $this->fields(['title' => 'Safe', 'return_to' => $unsafe]));
            self::assertSame('/admin/course-items/' . $this->itemId, $saved->headers->get('Location'), 'Not followed: ' . json_encode($unsafe));
        }
    }

    public function testOnlyKnownAdminDestinationsAreAccepted(): void
    {
        self::assertSame('/admin/courses/12/content', AdminCourseComponentController::safeReturn('/admin/courses/12/content'));
        self::assertSame('/admin/courses/12/content#course-node-7', AdminCourseComponentController::safeReturn('/admin/courses/12/content#course-node-7'));
        self::assertSame('/admin/course-items', AdminCourseComponentController::safeReturn('/admin/course-items'));
        foreach (['', 'https://catto.test/admin/course-items', '//catto.test/admin/course-items', '/admin/courses/12/content#x', '/admin/courses/12/content/', '/admin/courses/-1/content', "/admin/courses/12/content\n", '/admin/course-items/5'] as $value) {
            self::assertNull(AdminCourseComponentController::safeReturn($value), json_encode($value) . ' is refused.');
        }
    }

    /**
     * @param array<string,string> $changes
     * @return array<string,string>
     */
    private function fields(array $changes): array
    {
        return $changes + ['item_key' => 'shared-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Shared lesson', 'content_source' => '<p>Body</p>'];
    }

    private function ok(string $url): string
    {
        $response = $this->send(Request::create($url, 'GET'));
        self::assertSame(200, $response->getStatusCode(), $url . ' must render: ' . substr(strip_tags((string) $response->getContent()), 0, 300));
        return (string) $response->getContent();
    }

    /** @param array<string,string> $fields */
    private function post(string $url, array $fields): Response
    {
        $_POST = $fields + ['csrf' => $_SESSION['csrf']];
        try {
            return $this->send(Request::create($url, 'POST', $_POST));
        } finally {
            $_POST = [];
        }
    }

    private function send(Request $request): Response
    {
        $_GET = $request->query->all();
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            return $kernel->handle($request);
        } finally {
            $kernel->shutdown();
            $_GET = [];
        }
    }
}
