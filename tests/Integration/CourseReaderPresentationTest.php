<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\LearningService;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Kernel;
use CattoLearning\Support\Env;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The learner course reader and the public preview through real route dispatch: section entries in
 * the Course Modules outline, a Downloadable File whose description appears once inside its card,
 * and the Download and paging actions as the shared primary action.
 *
 * Course: Week one (section with an introduction) > Lesson, Pack, Quiz (graded assessment); Plain
 * (section, no introduction, so not a reading stop); Later (section three days after the previous
 * one, so locked for a new learner) > Locked lesson.
 */
final class CourseReaderPresentationTest extends TestCase
{
    private const DESCRIPTION = 'Download the precedent pack used in this course.';

    private Database $db;
    private IntegrationDataFixture $fixture;
    private CourseItemService $items;
    private string $suffix;
    private string $slug;
    private string $storage;
    private int $owner;
    private int $course;
    private int $learner;
    /** @var array<string,int> */
    private array $nodes = [];
    /** @var array<string,mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $container = IntegrationContainer::get();
        $this->db = IntegrationContainer::db();
        $this->items = $container->get(CourseItemService::class);
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->slug = 'reader-' . $this->suffix;
        $this->storage = CliBootstrap::boot()['instance_root'] . '/storage';
        $this->owner = $this->fixture->createUser('Reader author ' . $this->suffix, 'reader-author-' . $this->suffix . '@seed.test');
        $company = $this->fixture->createCompany($this->owner, 'Reader ' . $this->suffix, 'reader-' . $this->suffix . '.seed.test');
        $this->course = $this->fixture->createCourse($this->owner, $company, $this->slug, 'Reader ' . $this->suffix);
        $this->db->executeStatement("UPDATE courses SET status='published' WHERE id=:id", ['id' => $this->course]);

        $resources = new ResourceLibraryService($container->get(CourseItemRepository::class), $this->storage, static fn(string $path): bool => is_file($path));
        $temporary = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive();
        $zip->open($temporary, \ZipArchive::OVERWRITE);
        $zip->addFromString('precedent.txt', 'Deed of transfer.');
        $zip->close();
        $resource = $resources->upload(['name' => 'pack-' . $this->suffix . '.zip', 'tmp_name' => $temporary, 'size' => filesize($temporary), 'error' => UPLOAD_ERR_OK], ['title' => 'Pack ' . $this->suffix, 'resource_type' => 'archive', 'original_filename' => 'pack-' . $this->suffix . '.zip'], $this->owner);
        unlink($temporary);

        $this->nodes['week'] = $this->items->addSection($this->course, ['title' => 'Week one', 'introduction_html' => '<p>Welcome to week one.</p>'], $this->owner);
        $lesson = $this->items->create(['item_key' => 'reader-lesson-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Lesson', 'description_html' => '<p>Lesson summary.</p>', 'content_source' => '<p>Lesson body.</p>'], $this->owner);
        $this->nodes['lesson'] = $this->items->addExisting($this->course, $lesson, ['parent_node_id' => (string) $this->nodes['week'], 'public_preview' => '1'], $this->owner);
        $pack = $this->items->create(['item_key' => 'reader-pack-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'Precedent pack', 'description_html' => '<p>' . self::DESCRIPTION . '</p>', 'resource_id' => (string) $resource], $this->owner);
        $this->nodes['pack'] = $this->items->addExisting($this->course, $pack, ['parent_node_id' => (string) $this->nodes['week'], 'public_preview' => '1'], $this->owner);
        $quiz = $this->items->create(['item_key' => 'reader-quiz-' . $this->suffix, 'item_type' => 'assessment', 'title' => 'Quiz'], $this->owner);
        $this->nodes['quiz'] = $this->items->addExisting($this->course, $quiz, ['parent_node_id' => (string) $this->nodes['week']], $this->owner);
        $this->nodes['plain'] = $this->items->addSection($this->course, ['title' => 'Plain'], $this->owner);
        $this->nodes['later'] = $this->items->addSection($this->course, ['title' => 'Later', 'introduction_html' => '<p>Later.</p>', 'delay_days' => '3'], $this->owner);
        $locked = $this->items->create(['item_key' => 'reader-locked-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Locked lesson', 'content_source' => '<p>Later body.</p>'], $this->owner);
        $this->items->addExisting($this->course, $locked, ['parent_node_id' => (string) $this->nodes['later']], $this->owner);

        $this->learner = $this->fixture->createUser('Reader learner ' . $this->suffix, 'reader-learner-' . $this->suffix . '@seed.test');
        $this->fixture->grantRole($this->learner, 'STUDENT');
        $this->db->executeStatement(
            "INSERT INTO course_enrolments (public_id,user_id,course_id,status,access_period_seconds,assigned_by_user_id,is_preview) VALUES (:public_id,:user,:course,'assigned',31536000,:owner,FALSE)",
            ['public_id' => \CattoLearning\Support\Uuid::v4(), 'user' => $this->learner, 'course' => $this->course, 'owner' => $this->owner]
        );
        $container->get(LearningService::class)->start($this->learner, $this->slug);
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($this->learner, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $this->cookies = $_COOKIE;
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $this->db->executeStatement('DELETE FROM course_enrolments WHERE course_id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM courses WHERE id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM course_items WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
        $this->db->executeStatement('DELETE FROM resources WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
        $file = $this->storage . '/course-resources/pack-' . $this->suffix . '.zip';
        if (is_file($file)) { unlink($file); }
        $this->fixture->cleanup();
    }

    public function testSectionsAreLabelledAndStyledInTheCourseModulesOutline(): void
    {
        $page = $this->page('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content');
        $week = $this->outlineEntry($page, $this->nodes['week']);
        self::assertSame(['cl-course-depth-1', 'cl-course-outline-item', 'cl-course-outline-section'], $this->classes($week), 'A section keeps its depth class and gains the section hook.');
        self::assertSame('Section: Week one', trim($page->query('./a', $week)->item(0)->textContent ?? ''), 'A section with an introduction is a link.');
        $plain = $this->outlineEntry($page, $this->nodes['plain']);
        self::assertSame('Section: Plain', trim($page->query('./strong', $plain)->item(0)->textContent ?? ''), 'A section with nothing to show is plain text.');
        $later = $this->outlineEntry($page, $this->nodes['later']);
        self::assertSame('Locked: Section: Later', trim($page->query('./span', $later)->item(0)->textContent ?? ''), 'A locked section keeps its locked state.');
        $lesson = $this->outlineEntry($page, $this->nodes['lesson']);
        self::assertSame(['cl-course-depth-2', 'cl-course-outline-item', 'is-current'], $this->classes($lesson), 'An item is not a section, keeps its depth and its current marker.');
        self::assertSame('Module 1 · Lesson', trim($page->query('./a', $lesson)->item(0)->textContent ?? ''), 'Items keep their own label and are not prefixed.');
        self::assertSame('page', $this->element($page->query('./a', $lesson)->item(0))->getAttribute('aria-current'));
        self::assertSame(0, $this->db->fetchOne("SELECT count(*) FROM course_sections WHERE title LIKE 'Section:%' AND node_id=:id", ['id' => $this->nodes['week']]), 'The stored title is unchanged.');
    }

    public function testTheLearnerSeesTheDownloadDescriptionOnceInsideItsCardWithThePrimaryAction(): void
    {
        $page = $this->page('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/content');
        $html = $page->document->saveHTML() ?: '';
        self::assertSame(1, substr_count($html, self::DESCRIPTION), 'The description appears exactly once.');
        self::assertSame(1, $page->query('//section[contains(concat(" ", @class, " "), " cl-course-item-download ")]//p[normalize-space()="' . self::DESCRIPTION . '"]')->length, 'It is inside the bordered download card.');
        self::assertMatchesRegularExpression('/^pack-' . $this->suffix . '\.zip · ZIP archive · \d+ bytes$/u', trim($page->query('//p[contains(@class, "cl-course-item-download-meta")]')->item(0)->textContent ?? ''), 'Filename, type and size remain.');
        $download = $this->element($page->query('//section[contains(@class, "cl-course-item-download")]//a[@download]')->item(0), 'A started learner can download.');
        self::assertSame(CourseItemRenderer::DOWNLOAD_ACTION_CLASSES, $download->getAttribute('class'));
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/download/reader-pack-' . $this->suffix, $download->getAttribute('href'));
        $paging = $page->query('//nav[contains(@class, "cl-course-item-paging")]/a');
        self::assertSame(2, $paging->length, 'Previous and Next remain.');
        foreach ($paging as $link) {
            self::assertSame('cl-ui-action cl-ui-action--primary cl-ui-action--normal', $this->element($link)->getAttribute('class'));
        }
        self::assertStringEndsWith('/item/' . $this->nodes['lesson'] . '/content', $this->element($paging->item(0))->getAttribute('href'));
    }

    public function testALessonDescriptionStillAppearsOnceAboveItsContent(): void
    {
        $html = $this->page('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content')->document->saveHTML() ?: '';
        self::assertSame(1, substr_count($html, 'Lesson summary.'));
        self::assertMatchesRegularExpression('~<div class="cl-course-item-description"><p>Lesson summary\.</p></div><p>Lesson body\.</p>~', $html);
    }

    public function testThePublicPreviewShowsTheSameSingleDescriptionAndNoDownloadLink(): void
    {
        $_COOKIE = $this->cookies;
        $page = $this->page('/courses/' . $this->slug . '/preview/' . $this->nodes['pack'] . '/content');
        $html = $page->document->saveHTML() ?: '';
        self::assertSame(1, substr_count($html, self::DESCRIPTION));
        self::assertSame(1, $page->query('//section[contains(@class, "cl-course-item-download")]//p[normalize-space()="' . self::DESCRIPTION . '"]')->length);
        self::assertSame(0, $page->query('//section[contains(@class, "cl-course-item-download")]//a')->length, 'No download link in a public preview.');
        self::assertStringContainsString('Available to download after you start the course.', $html);
        $lesson = $this->page('/courses/' . $this->slug . '/preview/' . $this->nodes['lesson'] . '/content')->document->saveHTML() ?: '';
        self::assertSame(1, substr_count($lesson, 'Lesson summary.'), 'Preview and learner mode present descriptions the same way.');
    }

    public function testNextAndPreviousStopAtSectionsEverywhereTheLearnerReads(): void
    {
        $assessment = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['quiz'] . '/assessment/reader-quiz-' . $this->suffix);
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['later'] . '/content', $assessment['Next →'] ?? null, "An assessment's Next goes to the following section with a page, skipping a heading-only section.");
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/content', $assessment['← Previous'] ?? null);
        $pack = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['quiz'] . '/assessment/reader-quiz-' . $this->suffix, $pack['Next →'] ?? null);
        $lesson = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['week'] . '/content', $lesson['← Previous'] ?? null, "A content page's Previous goes to its section.");
        $week = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['week'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content', $week['Next →'] ?? null, "A section's Next goes to its first row.");
    }

    public function testThePublicPreviewStepsThroughItsPublicItemsOnly(): void
    {
        // The public preview shows only public Course Items, and no sections, so its reading order is
        // those items: the same rule over the structure it is allowed to show.
        $_COOKIE = $this->cookies;
        $lesson = $this->paging('/courses/' . $this->slug . '/preview/' . $this->nodes['lesson'] . '/content');
        self::assertSame('/courses/' . $this->slug . '/preview/' . $this->nodes['pack'] . '/content', $lesson['Next →'] ?? null);
        self::assertArrayNotHasKey('← Previous', $lesson);
        $pack = $this->page('/courses/' . $this->slug . '/preview/' . $this->nodes['pack'] . '/content');
        self::assertSame(0, $pack->query('//nav[contains(@class, "cl-course-outline")]//*[starts-with(normalize-space(), "Section:")]')->length, 'No section entries in the public outline.');
        self::assertSame(404, $this->send('/courses/' . $this->slug . '/preview/' . $this->nodes['week'] . '/content')->getStatusCode(), 'Sections are not part of the public preview.');
    }

    /** @return array<string,string> Paging link label => href. */
    private function paging(string $url): array
    {
        $links = [];
        foreach ($this->page($url)->query('//nav[contains(@class, "cl-course-item-paging")]/a') as $link) {
            if ($link instanceof \DOMElement) { $links[trim($link->textContent)] = $link->getAttribute('href'); }
        }
        return $links;
    }

    private function send(string $url): \Symfony\Component\HttpFoundation\Response
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

    private function page(string $url): DOMXPath
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        $_GET = Request::create($url)->query->all();
        try {
            $response = $kernel->handle(Request::create($url, 'GET'));
            self::assertSame(200, $response->getStatusCode(), $url . ' must render: ' . substr(strip_tags((string) $response->getContent()), 0, 300));
            $document = new DOMDocument();
            libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8"?>' . (string) $response->getContent());
            libxml_clear_errors();
            return new DOMXPath($document);
        } finally {
            $kernel->shutdown();
            $_GET = [];
        }
    }

    private function outlineEntry(DOMXPath $page, int $node): \DOMElement
    {
        $entries = $page->query('//nav[contains(@class, "cl-course-outline")]/div[contains(@class, "cl-course-outline-item")]');
        $structure = array_map(static fn(array $row): int => (int) $row['id'], $this->db->fetchAllAssociative(
            'WITH RECURSIVE tree AS (SELECT n.*, ARRAY[n.position, n.id::int] AS path FROM course_structure_nodes n WHERE n.course_id=:course AND n.parent_node_id IS NULL UNION ALL SELECT n.*, t.path || ARRAY[n.position, n.id::int] FROM course_structure_nodes n JOIN tree t ON t.id=n.parent_node_id) SELECT id FROM tree ORDER BY path',
            ['course' => $this->course]
        ));
        $element = $entries->item((int) array_search($node, $structure, true));
        self::assertInstanceOf(\DOMElement::class, $element);
        return $element;
    }

    private function element(?\DOMNode $node, string $message = ''): \DOMElement
    {
        self::assertInstanceOf(\DOMElement::class, $node, $message);
        return $node;
    }

    /** @return list<string> */
    private function classes(\DOMElement $element): array
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        sort($classes);
        return $classes;
    }
}
