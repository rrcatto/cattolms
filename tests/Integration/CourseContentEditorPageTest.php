<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemService;
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
 * The Course Content editor through real route dispatch and strict Twig rendering, signed in as an
 * administrator: the page itself, "+ Add here" positions, the Move into page, and the
 * drag-and-drop save endpoint's fragment and refusal responses.
 */
final class CourseContentEditorPageTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private CourseItemService $items;
    private int $owner;
    private int $course;
    private int $section;
    private int $part;
    private int $lesson;
    private int $itemId;
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
        $suffix = $this->fixture->suffix();
        $this->owner = $this->fixture->createUser('Content Editor ' . $suffix, 'content-editor-' . $suffix . '@seed.test');
        $this->fixture->grantRole($this->owner, 'ADMIN');
        $this->fixture->attachToSystemCompany($this->owner);
        $company = $this->fixture->createCompany($this->owner, 'Content Editor ' . $suffix, 'content-editor-' . $suffix . '.seed.test');
        $this->course = $this->fixture->createCourse($this->owner, $company, 'content-editor-' . $suffix, 'Content Editor ' . $suffix);
        $this->section = $this->items->addSection($this->course, ['title' => 'Week one'], $this->owner);
        $this->part = $this->items->addSection($this->course, ['title' => 'Part one', 'parent_node_id' => (string) $this->section], $this->owner);
        $this->itemId = $this->items->create(['item_key' => 'content-editor-' . $suffix, 'item_type' => 'html_lesson', 'title' => 'Lesson', 'content_source' => '<p>Lesson</p>'], $this->owner);
        $this->lesson = $this->items->addExisting($this->course, $this->itemId, ['parent_node_id' => (string) $this->part], $this->owner);

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
        $this->db->executeStatement('DELETE FROM courses WHERE id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM course_items WHERE id=:id', ['id' => $this->itemId]);
        $this->fixture->cleanup();
    }

    public function testTheEditorRendersTheNestedTreeWithItsControls(): void
    {
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        foreach ([$this->section, $this->part, $this->lesson] as $node) {
            self::assertStringContainsString('id="course-node-' . $node . '"', $html);
        }
        self::assertStringContainsString('data-controller="course-content"', $html);
        self::assertStringContainsString('aria-controls="course-branch-' . $this->section . '"', $html);
        self::assertStringContainsString('aria-label="Actions for Lesson"', $html);
        self::assertStringContainsString('aria-label="Move Lesson"', $html);
        self::assertStringContainsString('Add here', $html);
        foreach (['add-new', 'add-existing', 'add-section'] as $panel) {
            self::assertStringContainsString('id="' . $panel . '"', $html);
        }
        self::assertStringNotContainsString('save-arrangement', $html, 'Moves save at once; there is no staged arrangement.');
        // Component icons use the same fingerprinted sprite as the navigation, so a symbol added to
        // the sprite (the drag handle's) reaches a browser that cached the previous file.
        self::assertSame(1, preg_match('~<use href="(/img/nav-icons\.svg\?v=[0-9a-f]{16})#action-drag"~', $html, $handle), 'The drag handle icon uses the fingerprinted sprite.');
        self::assertStringContainsString('href="' . $handle[1] . '#nav-', $html, 'The navigation uses the same sprite URL.');
        self::assertStringNotContainsString('href="/img/nav-icons.svg#', $html, 'No icon uses the unversioned sprite.');
    }

    public function testAddHereCarriesTheExactPlaceIntoTheAddPanels(): void
    {
        $html = $this->ok('/admin/courses/' . $this->course . '/content?insert_parent=' . $this->section . '&insert_index=1');
        self::assertStringContainsString('name="insert_index" value="1"', $html);
        self::assertStringContainsString('inside “Week one”, as row 2', $html);
        $form = $this->ok('/admin/course-items/new?course_id=' . $this->course . '&type=html_lesson&parent_node_id=' . $this->section . '&insert_index=0');
        self::assertStringContainsString('name="insert_index" value="0"', $form);
    }

    public function testTheMoveIntoPageOffersOnlyValidDestinations(): void
    {
        $html = $this->ok('/admin/courses/' . $this->course . '/content/move-into?node_ids%5B%5D=' . $this->part);
        self::assertStringContainsString('Section · Week one', $html);
        self::assertStringNotContainsString('Section · Part one', $html, 'A row cannot move into itself.');
        self::assertStringNotContainsString('Item · Lesson', $html, 'A row cannot move into its own descendant.');
    }

    public function testTheDragEndpointSavesAndReturnsTheTree(): void
    {
        $response = $this->post('/admin/courses/' . $this->course . '/content/arrange', ['node_ids' => [(string) $this->lesson], 'parent_node_id' => '', 'index' => '0']);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('id="course-content-tree"', (string) $response->getContent());
        self::assertStringContainsString('data-error=""', (string) $response->getContent());
        self::assertSame($this->lesson, (int) $this->db->fetchOne('SELECT id FROM course_structure_nodes WHERE course_id=:course AND parent_node_id IS NULL ORDER BY position LIMIT 1', ['course' => $this->course]));
    }

    public function testARefusedDropReturnsTheAuthoritativeTreeAndTheReason(): void
    {
        $response = $this->post('/admin/courses/' . $this->course . '/content/arrange', ['node_ids' => [(string) $this->section], 'parent_node_id' => (string) $this->lesson, 'index' => '']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('data-error="A row cannot be moved inside itself', (string) $response->getContent());
        self::assertNull($this->db->fetchOne('SELECT parent_node_id FROM course_structure_nodes WHERE id=:id', ['id' => $this->section]));
    }

    private function ok(string $url): string
    {
        $response = $this->send(Request::create($url, 'GET'));
        self::assertSame(200, $response->getStatusCode(), $url . ' must render: ' . substr(strip_tags((string) $response->getContent()), 0, 400));
        return (string) $response->getContent();
    }

    /** @param array<string,mixed> $fields */
    private function post(string $url, array $fields): Response
    {
        $_POST = $fields + ['csrf' => $_SESSION['csrf']];
        try {
            $request = Request::create($url, 'POST', $_POST);
            $request->headers->set('HX-Request', 'true');
            $_SERVER['HTTP_HX_REQUEST'] = 'true';
            return $this->send($request);
        } finally {
            $_POST = [];
            unset($_SERVER['HTTP_HX_REQUEST']);
        }
    }

    private function send(Request $request): Response
    {
        // The controllers read the native superglobals, as they do behind PHP-FPM.
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
