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
    private string $suffix;
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
        $suffix = $this->suffix = $this->fixture->suffix();
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
        $this->db->executeStatement('DELETE FROM analytics_events WHERE user_id=:owner OR course_id=:course', ['owner' => $this->owner, 'course' => $this->course]);
        $this->db->executeStatement('DELETE FROM courses WHERE owner_user_id=:owner', ['owner' => $this->owner]);
        $this->db->executeStatement('DELETE FROM course_items WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
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
        self::assertStringNotContainsString('save-arrangement', $html, 'Moves save at once; there is no staged arrangement.');
        // Component icons use the same fingerprinted sprite as the navigation, so a symbol added to
        // the sprite (the drag handle's) reaches a browser that cached the previous file.
        self::assertSame(1, preg_match('~<use href="(/img/nav-icons\.svg\?v=[0-9a-f]{16})#action-drag"~', $html, $handle), 'The drag handle icon uses the fingerprinted sprite.');
        self::assertStringContainsString('href="' . $handle[1] . '#nav-', $html, 'The navigation uses the same sprite URL.');
        self::assertStringNotContainsString('href="/img/nav-icons.svg#', $html, 'No icon uses the unversioned sprite.');
    }

    public function testTheTopAddItemAreaIsGoneAndAddHereOpensTheInsertModal(): void
    {
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        foreach (['id="add-item"', 'id="add-new"', 'id="add-existing"', 'id="add-section"', '>Add item<'] as $removed) {
            self::assertStringNotContainsString($removed, $html, 'The top Add item area is removed: ' . $removed);
        }
        self::assertStringContainsString('id="course-content-insert"', $html, 'One insert modal.');
        self::assertStringContainsString('id="course-content-insert-body"', $html);
        $existing = '/admin/courses/' . $this->course . '/content/insert/existing?insert_parent=' . $this->section . '&amp;insert_index=0';
        self::assertStringContainsString('href="' . $existing . '"', $html, 'Without JavaScript the link opens the form on its own page.');
        self::assertStringContainsString('hx-get="' . $existing . '" hx-target="#course-content-insert-body"', $html, 'With JavaScript the form loads into the modal.');
        self::assertStringContainsString('data-open-modal="course-content-insert"', $html);
    }

    public function testAddHereIsOfferedAtEveryPlace(): void
    {
        $empty = $this->items->addSection($this->course, ['title' => 'Empty'], $this->owner);
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        $places = [
            ['', 0, 'the start of the course'],
            [(string) $this->section, 0, 'the start of a section'],
            [(string) $this->part, 1, 'after the last row of a nested section'],
            ['', 1, 'between rows at the outer level'],
            [(string) $empty, 0, 'inside an empty section'],
            ['', 2, 'the end of the course'],
        ];
        foreach ($places as [$parent, $index, $label]) {
            self::assertStringContainsString('content/insert/section?insert_parent=' . $parent . '&amp;insert_index=' . $index . '"', $html, '+ Add here at ' . $label . '.');
        }
        $create = '/admin/course-items/new?course_id=' . $this->course . '&amp;parent_node_id=' . $empty . '&amp;insert_index=0';
        self::assertStringContainsString('hx-get="' . $create . '"', $html, 'Create new item knows the place too.');
    }

    public function testAnEmptyCourseStillOffersAddHere(): void
    {
        $this->db->executeStatement('DELETE FROM course_structure_nodes WHERE course_id=:course', ['course' => $this->course]);
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        self::assertStringContainsString('No Course Content yet', $html);
        self::assertStringContainsString('aria-label="Add here, in this empty course"', $html);
        self::assertStringContainsString('content/insert/section?insert_parent=&amp;insert_index=0"', $html);
        $node = $this->insertedNode($this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => 'First', 'parent_node_id' => '', 'insert_index' => '0']));
        self::assertSame([$node], $this->children(null));
    }

    public function testTheModalFormsLoadAsFragmentsAndAsPagesWithoutJavaScript(): void
    {
        $base = '/admin/courses/' . $this->course . '/content/insert/';
        $section = $this->ok($base . 'section?insert_parent=' . $this->section . '&insert_index=1', true);
        self::assertStringNotContainsString('<!doctype html>', $section, 'The modal receives the form alone.');
        self::assertStringContainsString('hx-post="/admin/courses/' . $this->course . '/content/sections"', $section);
        self::assertStringContainsString('inside “Week one”, as row 2', $section);
        self::assertStringContainsString('data-close-modal', $section, 'Cancel closes the modal.');
        $existing = $this->ok($base . 'existing?insert_parent=&insert_index=0&q=content-editor', true);
        self::assertStringContainsString('hx-post="/admin/courses/' . $this->course . '/content/placements"', $existing);
        self::assertStringContainsString('value="' . $this->itemId . '"', $existing, 'The search lists matching Course Items.');
        $types = $this->ok('/admin/course-items/new?course_id=' . $this->course . '&parent_node_id=' . $this->section . '&insert_index=0', true);
        self::assertStringContainsString('hx-get="/admin/course-items/new"', $types);
        self::assertStringContainsString('name="insert_index" value="0"', $types);
        $form = $this->ok('/admin/course-items/new?course_id=' . $this->course . '&type=html_lesson&parent_node_id=' . $this->section . '&insert_index=0', true);
        self::assertStringContainsString('hx-post="/admin/course-items"', $form);
        self::assertStringContainsString('Create new item · HTML lesson', $form);
        self::assertStringContainsString('name="insert_index" value="0"', $form);

        $page = $this->ok($base . 'section?insert_parent=' . $this->section . '&insert_index=1');
        self::assertStringContainsString('<!doctype html>', $page);
        self::assertStringNotContainsString('hx-post=', $page, 'Without JavaScript the form posts normally.');
        self::assertStringContainsString('name="insert_index" value="1"', $page);
    }

    public function testEachActionInsertsAtTheChosenPlace(): void
    {
        $section = $this->insertedNode($this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => 'Between', 'introduction_html' => '<p>Intro</p>', 'delay_days' => '2', 'parent_node_id' => (string) $this->section, 'insert_index' => '0']));
        self::assertSame([$section, $this->part], $this->children($this->section), 'Add section lands first inside Week one.');
        self::assertSame(2880, (int) $this->db->fetchOne('SELECT relative_delay_minutes FROM course_structure_nodes WHERE id=:id', ['id' => $section]), 'Its availability is kept.');

        $placed = $this->insertedNode($this->post('/admin/courses/' . $this->course . '/content/placements', ['course_item_id' => (string) $this->itemId, 'parent_node_id' => '', 'insert_index' => '0']));
        self::assertSame([$placed, $this->section], $this->children(null), 'Add existing item lands at the start of the course.');

        $response = $this->post('/admin/course-items', ['course_id' => (string) $this->course, 'item_type' => 'html_lesson', 'item_key' => 'inserted-' . $this->suffix, 'title' => 'Inserted', 'content_source' => '<p>New</p>', 'parent_node_id' => (string) $section, 'insert_index' => '0']);
        $created = $this->insertedNode($response);
        self::assertSame([$created], $this->children($section), 'Create new item lands inside the empty section, already attached.');
        self::assertSame('Inserted', $this->db->fetchOne('SELECT ci.title FROM course_item_placements p JOIN course_items ci ON ci.id=p.course_item_id WHERE p.node_id=:node', ['node' => $created]));

        $plain = $this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => 'Plain', 'parent_node_id' => '', 'insert_index' => '1'], false);
        self::assertSame(303, $plain->getStatusCode(), 'Without JavaScript the form returns to Course Content.');
        self::assertMatchesRegularExpression('~/admin/courses/' . $this->course . '/content#course-node-\d+$~', (string) $plain->headers->get('Location'));
        self::assertSame($placed, $this->children(null)[0]);
    }

    public function testASectionCanBeMarkedForThePublicPreview(): void
    {
        $node = $this->insertedNode($this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => 'Open to all', 'public_preview' => '1', 'parent_node_id' => '', 'insert_index' => '0']));
        self::assertTrue((bool) $this->db->fetchOne('SELECT public_preview FROM course_sections WHERE node_id=:id', ['id' => $node]));
        $html = $this->ok('/admin/courses/' . $this->course . '/content');
        self::assertMatchesRegularExpression('~action="/admin/courses/' . $this->course . '/content/' . $node . '/section".*?name="public_preview" value="1" checked~s', $html, 'Edit section shows the flag, so saving it keeps it.');
        self::assertStringNotContainsString('name="public_preview" value="1" checked', substr($html, (int) strpos($html, 'action="/admin/courses/' . $this->course . '/content/' . $this->section . '/section"'), 4000), 'A section not marked public is unchecked.');
        $form = $this->ok('/admin/courses/' . $this->course . '/content/insert/section?insert_parent=&insert_index=0', true);
        self::assertStringContainsString('name="public_preview"', $form, 'Add section offers the flag too.');
    }

    public function testRefusedInsertionsChangeNothingAndKeepWhatWasEntered(): void
    {
        $before = $this->snapshot();
        $other = $this->fixture->createCourse($this->owner, (int) $this->db->fetchOne('SELECT owner_company_id FROM courses WHERE id=:id', ['id' => $this->course]), 'content-editor-other-' . $this->suffix, 'Other ' . $this->suffix);
        $foreign = $this->items->addSection($other, ['title' => 'Elsewhere'], $this->owner);

        $missingTitle = $this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => '', 'introduction_html' => '<p>Kept intro</p>', 'parent_node_id' => (string) $this->section, 'insert_index' => '0']);
        self::assertSame(422, $missingTitle->getStatusCode());
        self::assertStringContainsString('A section needs a title.', (string) $missingTitle->getContent());
        self::assertStringContainsString('&lt;p&gt;Kept intro&lt;/p&gt;', (string) $missingTitle->getContent(), 'Entered values stay in the form.');

        $tooDeep = $this->post('/admin/courses/' . $this->course . '/content/sections', ['title' => 'Too deep', 'parent_node_id' => (string) $this->lesson, 'insert_index' => '0']);
        self::assertSame(422, $tooDeep->getStatusCode());
        self::assertStringContainsString('at most 3 levels', (string) $tooDeep->getContent());

        $foreignParent = $this->post('/admin/courses/' . $this->course . '/content/placements', ['course_item_id' => (string) $this->itemId, 'parent_node_id' => (string) $foreign, 'insert_index' => '0']);
        self::assertSame(422, $foreignParent->getStatusCode());
        self::assertStringContainsString('not part of this course', (string) $foreignParent->getContent());

        $badIndex = $this->post('/admin/courses/' . $this->course . '/content/placements', ['course_item_id' => (string) $this->itemId, 'parent_node_id' => (string) $this->section, 'insert_index' => '7']);
        self::assertSame(422, $badIndex->getStatusCode());
        self::assertStringContainsString('valid position', (string) $badIndex->getContent());

        $noItem = $this->post('/admin/courses/' . $this->course . '/content/placements', ['course_item_id' => '999999999', 'parent_node_id' => '', 'insert_index' => '0']);
        self::assertSame(422, $noItem->getStatusCode());

        $duplicateKey = $this->post('/admin/course-items', ['course_id' => (string) $this->course, 'item_type' => 'html_lesson', 'item_key' => 'content-editor-' . $this->suffix, 'title' => 'Kept title', 'parent_node_id' => (string) $this->section, 'insert_index' => '0']);
        self::assertSame(422, $duplicateKey->getStatusCode());
        self::assertStringContainsString('value="Kept title"', (string) $duplicateKey->getContent(), 'The modal form keeps what was entered.');
        self::assertStringContainsString('hx-post="/admin/course-items"', (string) $duplicateKey->getContent());

        $badPlace = $this->post('/admin/course-items', ['course_id' => (string) $this->course, 'item_type' => 'html_lesson', 'item_key' => 'orphan-' . $this->suffix, 'title' => 'Orphan', 'parent_node_id' => (string) $foreign, 'insert_index' => '0']);
        self::assertSame(422, $badPlace->getStatusCode());
        self::assertFalse($this->db->fetchOne('SELECT id FROM course_items WHERE item_key=:key', ['key' => 'orphan-' . $this->suffix]), 'A refused placement leaves no new Course Item behind.');

        $refused = $this->send($this->htmx(Request::create('/admin/courses/' . $this->course . '/content/insert/section?insert_parent=' . $foreign . '&insert_index=0', 'GET')));
        self::assertSame(422, $refused->getStatusCode(), 'A place outside the course is refused before any form is shown.');
        self::assertStringContainsString('no longer in this course', (string) $refused->getContent());

        self::assertSame($before, $this->snapshot(), 'Nothing in this course changed.');
    }

    public function testAdminEditingAndPreviewsAreNotCourseViews(): void
    {
        $slug = (string) $this->db->fetchOne('SELECT slug FROM courses WHERE id=:id', ['id' => $this->course]);
        $this->ok('/admin/courses/' . $this->course . '/content');
        $this->ok('/admin/course-items/' . $this->itemId);
        $this->db->executeStatement('UPDATE course_item_placements SET public_preview=TRUE WHERE node_id=:node', ['node' => $this->lesson]);
        $this->ok('/courses/' . $slug . '/preview?preview=1');
        $this->ok('/courses/' . $slug . '/preview/' . $this->lesson . '/content?preview=1');
        $this->send(Request::create('/learn/' . $slug . '?preview=1', 'GET'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM analytics_events WHERE course_id=:course', ['course' => $this->course]), 'Authoring and ADMIN previews record no views.');
    }

    public function testTheAnalyticsEventsPageShowsRecordedEvents(): void
    {
        $this->db->executeStatement("INSERT INTO analytics_events (event_type,source,occurred_at,user_id,course_id,metadata) VALUES ('course_view','course_detail',NOW(),:user,:course,'{\"context\":\"course_page\"}')", ['user' => $this->owner, 'course' => $this->course]);
        $html = $this->ok('/admin/analytics/events?type=course_view&course_id=' . $this->course);
        self::assertStringContainsString('Analytics events', $html);
        self::assertStringContainsString('<code>course_view</code>', $html);
        self::assertStringContainsString('Content Editor ' . $this->suffix, $html, 'The course and person are named.');
        self::assertStringContainsString('context: course_page', $html);
        self::assertStringContainsString('1 event match', $html);
        $this->db->executeStatement('DELETE FROM analytics_events WHERE course_id=:course', ['course' => $this->course]);
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

    private function ok(string $url, bool $htmx = false): string
    {
        $request = Request::create($url, 'GET');
        $response = $this->send($htmx ? $this->htmx($request) : $request);
        self::assertSame(200, $response->getStatusCode(), $url . ' must render: ' . substr(strip_tags((string) $response->getContent()), 0, 400));
        return (string) $response->getContent();
    }

    /** @param array<string,mixed> $fields */
    private function post(string $url, array $fields, bool $htmx = true): Response
    {
        $_POST = $fields + ['csrf' => $_SESSION['csrf']];
        try {
            $request = Request::create($url, 'POST', $_POST);
            return $this->send($htmx ? $this->htmx($request) : $request);
        } finally {
            $_POST = [];
        }
    }

    private function htmx(Request $request): Request
    {
        $request->headers->set('HX-Request', 'true');
        return $request;
    }

    /** The node a successful modal insertion reports to the editor. */
    private function insertedNode(Response $response): int
    {
        self::assertSame(204, $response->getStatusCode(), 'A successful insertion has nothing to swap: ' . substr(strip_tags((string) $response->getContent()), 0, 300));
        $trigger = json_decode((string) $response->headers->get('HX-Trigger'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('#course-content-insert', $trigger['course-content-inserted']['target'] ?? null, 'The event goes to the insert modal.');
        return (int) $trigger['course-content-inserted']['node'];
    }

    /** @return list<int> */
    private function children(?int $parent): array
    {
        return array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM course_structure_nodes WHERE course_id=:course AND parent_node_id IS NOT DISTINCT FROM :parent ORDER BY position', ['course' => $this->course, 'parent' => $parent]));
    }

    /** @return list<array<string,mixed>> */
    private function snapshot(): array
    {
        return $this->db->fetchAllAssociative('SELECT id,parent_node_id,position,updated_at FROM course_structure_nodes WHERE course_id=:course ORDER BY id', ['course' => $this->course]);
    }

    private function send(Request $request): Response
    {
        // The controllers read the native superglobals, as they do behind PHP-FPM.
        $_GET = $request->query->all();
        $hx = $request->headers->get('HX-Request');
        if ($hx === null) { unset($_SERVER['HTTP_HX_REQUEST']); } else { $_SERVER['HTTP_HX_REQUEST'] = $hx; }
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            return $kernel->handle($request);
        } finally {
            $kernel->shutdown();
            $_GET = [];
            unset($_SERVER['HTTP_HX_REQUEST']);
        }
    }
}
