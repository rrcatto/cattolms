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
        $this->db->executeStatement('DELETE FROM course_favourites WHERE course_id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM analytics_events WHERE course_id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM courses WHERE id=:id', ['id' => $this->course]);
        $this->db->executeStatement('DELETE FROM course_items WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
        $this->db->executeStatement('DELETE FROM resources WHERE created_by_user_id=:owner', ['owner' => $this->owner]);
        $file = $this->storage . '/course-resources/pack-' . $this->suffix . '.zip';
        if (is_file($file)) { unlink($file); }
        $this->fixture->cleanup();
    }

    /** Only the section the reader is in starts open in the sidebar (owner, 2026-11-02), and the browser does the rest. */
    public function testOnlyTheCurrentSectionStartsOpenInTheOutline(): void
    {
        $page = $this->page('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content');
        $week = $this->outlineEntry($page, $this->nodes['week']);
        $group = $this->element($week->parentNode);
        self::assertSame('summary', $week->nodeName, 'A section with parts is the heading of a panel.');
        self::assertSame('details', $group->nodeName);
        self::assertTrue($group->hasAttribute('open'), 'The section holding the current part is open.');
        self::assertSame(1, $page->query('//nav[contains(@class, "cl-course-outline")]//details[@open]')->length, 'and no other.');
        self::assertSame(1, $page->query('./*[contains(@class, "cl-course-outline-toggle")]', $week)->length, 'It has its arrow.');
        self::assertSame(1, $page->query('.//*[contains(@class, "is-current")]', $group)->length, 'The current part is inside it.');
        $introduction = $this->page('/learn/' . $this->slug);
        self::assertSame(0, $introduction->query('//nav[contains(@class, "cl-course-outline")]//details[@open]')->length, 'The course introduction opens none.');
    }

    public function testMarkCompleteAnswersHtmxInPlaceAndAPlainFormComesBackToTheButtons(): void
    {
        $saved = [$_SERVER, $_SESSION['csrf'] ?? null];
        $_SESSION['csrf'] = str_repeat('m', 64);
        $form = ['csrf' => $_SESSION['csrf'], 'completed' => '1'];
        $learner = '/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/complete';
        $visitor = '/courses/' . $this->slug . '/preview/' . $this->nodes['lesson'] . '/complete';
        $done = fn(): int => (int) $this->db->fetchOne('SELECT count(*) FROM course_part_completions c JOIN course_enrolments e ON e.id = c.enrolment_id WHERE e.user_id = :user AND e.course_id = :course', ['user' => $this->learner, 'course' => $this->course]);
        try {
            // Without JavaScript the plain form comes back to the buttons.
            unset($_SERVER['HTTP_HX_REQUEST']);
            $plain = $this->post($learner, $form);
            self::assertSame(303, $plain->getStatusCode());
            self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content#cl-course-item-paging', $plain->headers->get('Location'));
            self::assertSame(1, $done());
            $this->post($learner, ['completed' => '0'] + $form);
            self::assertSame(0, $done());

            // With it, the answer is the form and only what the tick changed, with the lesson's row and its section's.
            $_SERVER['HTTP_HX_REQUEST'] = 'true';
            $answer = $this->post($learner, $form);
            self::assertSame(200, $answer->getStatusCode());
            $html = (string) $answer->getContent();
            self::assertStringNotContainsString('<html', $html, 'Only the changed parts are sent.');
            self::assertStringContainsString('class="cl-course-item-complete is-done"', $html);
            self::assertStringContainsString('name="completed" value="0"', $html, 'Pressed again, it unticks.');
            self::assertStringContainsString('Completed', $html);
            foreach (['#cl-course-reader-progress', '#cl-outline-row-' . $this->nodes['lesson'], '#cl-outline-row-' . $this->nodes['week']] as $target) {
                self::assertStringContainsString('<hx-partial hx-target="' . $target . '" hx-swap="outerHTML">', $html);
            }
            self::assertStringNotContainsString('#cl-outline-row-' . $this->nodes['pack'], $html, 'A row the tick did not change stays as it is.');
            self::assertStringContainsString('<summary id="cl-outline-row-' . $this->nodes['week'] . '"', $html, 'A section stays the heading of its panel.');
            self::assertStringContainsString('1 of', $html);
            self::assertSame(1, $done());

            // Whatever cannot be swapped in reloads the page: a refusal, an expired form, a signed-out reader.
            $week = $this->post('/learn/' . $this->slug . '/item/' . $this->nodes['week'] . '/complete', $form);
            self::assertSame(['true', ''], [$week->headers->get('HX-Refresh'), (string) $week->getContent()], 'A section cannot be ticked by hand.');
            $expired = $this->post($learner, ['csrf' => 'expired', 'completed' => '0']);
            self::assertSame(['true', ''], [$expired->headers->get('HX-Refresh'), (string) $expired->getContent()]);
            self::assertSame(1, $done(), 'An expired form changes nothing.');
            $signedIn = $_COOKIE;
            unset($_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')]);
            $away = $this->post($learner, ['completed' => '0'] + $form);
            self::assertSame(['true', ''], [$away->headers->get('HX-Refresh'), (string) $away->getContent()]);
            self::assertSame(1, $done());

            // The public preview answers the same way, from the visitor's session.
            $ticks = $_SESSION['public_preview_done'] ?? null;
            unset($_SESSION['public_preview_done']);
            $publicAnswer = $this->post($visitor, ['csrf' => (string) $_SESSION['csrf']] + $form);
            $public = (string) $publicAnswer->getContent();
            self::assertSame(200, $publicAnswer->getStatusCode());
            self::assertNull($publicAnswer->headers->get('HX-Refresh'));
            self::assertStringNotContainsString('<html', $public);
            self::assertStringContainsString('class="cl-course-item-complete is-done"', $public);
            self::assertStringContainsString('action="' . $visitor . '"', $public);
            self::assertStringContainsString('<hx-partial hx-target="#cl-outline-row-' . $this->nodes['lesson'] . '" hx-swap="outerHTML">', $public);
            self::assertStringContainsString('/courses/' . $this->slug . '/preview/' . $this->nodes['lesson'] . '/content', $public, 'Its rows stay in the public preview.');
            self::assertTrue($_SESSION['public_preview_done'][$this->course][$this->nodes['lesson']] ?? false);
            self::assertSame(1, $done(), 'A visitor\'s tick is kept in their session, never on a record.');
            self::assertSame(['true', ''], (function () use ($visitor): array { $r = $this->post($visitor, ['csrf' => 'expired', 'completed' => '0']); return [$r->headers->get('HX-Refresh'), (string) $r->getContent()]; })());
            if ($ticks === null) { unset($_SESSION['public_preview_done']); } else { $_SESSION['public_preview_done'] = $ticks; }
            $_COOKIE = $signedIn;
        } finally {
            $_SERVER = $saved[0];
            $_SESSION['csrf'] = $saved[1];
            $this->db->executeStatement('DELETE FROM course_part_completions WHERE enrolment_id IN (SELECT id FROM course_enrolments WHERE course_id = :course)', ['course' => $this->course]);
        }
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
        $paging = $page->query('//nav[contains(@class, "cl-course-item-paging")]//a');
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
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['later'] . '/content', $assessment['Next'] ?? null, "An assessment's Next goes to the following section with a page, skipping a heading-only section.");
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/content', $assessment['Previous'] ?? null);
        $pack = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['pack'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['quiz'] . '/assessment/reader-quiz-' . $this->suffix, $pack['Next'] ?? null);
        $lesson = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['week'] . '/content', $lesson['Previous'] ?? null, "A content page's Previous goes to its section.");
        $week = $this->paging('/learn/' . $this->slug . '/item/' . $this->nodes['week'] . '/content');
        self::assertSame('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content', $week['Next'] ?? null, "A section's Next goes to its first row.");
    }

    public function testASectionAppearsInThePublicPreviewOnlyWhenMarkedPublic(): void
    {
        $_COOKIE = $this->cookies;
        $base = '/courses/' . $this->slug . '/preview/';
        $pack = $this->page($base . $this->nodes['pack'] . '/content');
        self::assertSame(0, $pack->query('//nav[contains(@class, "cl-course-outline")]//*[starts-with(normalize-space(), "Section:")]')->length, 'Sections are not public by default.');
        self::assertSame(404, $this->send($base . $this->nodes['week'] . '/content')->getStatusCode());
        $lesson = $this->paging($base . $this->nodes['lesson'] . '/content');
        self::assertArrayNotHasKey('Previous', $lesson);

        $this->items->updateSection($this->course, $this->nodes['week'], ['title' => 'Week one', 'introduction_html' => '<p>Welcome to week one.</p>', 'show_outline' => '1', 'public_preview' => '1'], $this->owner);
        $section = $this->page($base . $this->nodes['week'] . '/content');
        $html = $section->document->saveHTML() ?: '';
        self::assertStringContainsString('Welcome to week one.', $html, 'A public section opens with its introduction.');
        self::assertSame('Week one', trim($section->query('//article//h1')->item(0)->textContent ?? ''));
        self::assertSame(['Lesson', 'Precedent pack'], array_map(static fn(\DOMNode $li): string => trim($li->textContent), iterator_to_array($section->query('//article//ol/li'))), 'Its outline lists only what the public may see.');
        $link = $section->query('//nav[contains(@class, "cl-course-outline")]//a[normalize-space()="Section: Week one"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $link, 'The public outline shows and links the section.');
        self::assertSame($base . $this->nodes['week'] . '/content', $link->getAttribute('href'));
        self::assertSame($base . $this->nodes['lesson'] . '/content', $this->paging($base . $this->nodes['week'] . '/content')['Next'] ?? null);
        self::assertSame($base . $this->nodes['week'] . '/content', $this->paging($base . $this->nodes['lesson'] . '/content')['Previous'] ?? null, 'Previous and Next include the public section.');
        self::assertSame(404, $this->send($base . $this->nodes['later'] . '/content')->getStatusCode(), 'A section not marked public stays out.');
        self::assertTrue((bool) $this->db->fetchOne('SELECT public_preview FROM course_sections WHERE node_id=:id', ['id' => $this->nodes['week']]));
    }

    public function testCourseViewsAreRecordedWithTheirSourceAndViewer(): void
    {
        $this->page('/learn/' . $this->slug);
        $this->page('/learn/' . $this->slug . '/item/' . $this->nodes['lesson'] . '/content');
        $views = $this->views();
        self::assertSame(['learner_reader', 'learner_reader'], array_column($views, 'source'));
        self::assertSame([$this->learner, $this->learner], array_map('intval', array_column($views, 'user_id')), 'A signed-in learner is associated by id.');
        self::assertSame([['context' => 'course_home'], ['context' => 'item', 'node_id' => $this->nodes['lesson']]], array_map(static fn(array $view): array => json_decode((string) $view['metadata'], true), $views));
        self::assertNotNull($views[1]['course_item_id'], 'An item view names the Course Item.');
        self::assertSame($views[0]['visitor_id'], $views[1]['visitor_id'], 'One browser session is one visitor.');

        $_COOKIE = $this->cookies;
        unset($_SESSION['analytics_visitor']);
        $this->page('/courses/' . $this->slug);
        $this->page('/courses/' . $this->slug . '/preview');
        $anonymous = array_slice($this->views(), 2);
        self::assertSame(['course_detail', 'public_preview'], array_column($anonymous, 'source'));
        self::assertSame([null, null], array_column($anonymous, 'user_id'), 'A visitor who is not signed in has no person.');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $anonymous[0]['visitor_id'], 'Only a random per-session visitor id.');
        self::assertNotSame($views[0]['visitor_id'], $anonymous[0]['visitor_id']);
    }

    public function testAFavouriteRequestRecordsOnlyARealChange(): void
    {
        $_SESSION['csrf'] = str_repeat('b', 64);
        foreach (['1', '1', '0', '0'] as $wanted) {
            $this->favourite($wanted);
        }
        self::assertSame(['course_favourite_added', 'course_favourite_removed'], array_column($this->db->fetchAllAssociative("SELECT event_type FROM analytics_events WHERE course_id=:course AND event_type LIKE 'course_favourite%' ORDER BY id", ['course' => $this->course]), 'event_type'), 'Asking twice for the same state records it once.');
        self::assertSame('course_detail', $this->db->fetchOne("SELECT source FROM analytics_events WHERE course_id=:course AND event_type='course_favourite_added'", ['course' => $this->course]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_favourites WHERE course_id=:course', ['course' => $this->course]), 'The favourites table holds the current state.');
    }

    /** @return list<array<string,mixed>> */
    private function views(): array
    {
        return $this->db->fetchAllAssociative("SELECT source, user_id, visitor_id::text AS visitor_id, course_item_id, metadata::text AS metadata FROM analytics_events WHERE course_id=:course AND event_type='course_view' ORDER BY id", ['course' => $this->course]);
    }

    private function favourite(string $wanted): void
    {
        $_POST = ['csrf' => $_SESSION['csrf'], 'course_id' => (string) $this->course, 'favourite' => $wanted, 'return' => '/courses/' . $this->slug];
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            $response = $kernel->handle(Request::create('/courses/favourite', 'POST', $_POST));
            self::assertContains($response->getStatusCode(), [302, 303], 'The favourite form redirects back.');
        } finally {
            $kernel->shutdown();
            $_POST = [];
        }
    }

    /** @return array<string,string> Paging link label => href. */
    private function paging(string $url): array
    {
        $links = [];
        foreach ($this->page($url)->query('//nav[contains(@class, "cl-course-item-paging")]//a') as $link) {
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

    /** @param array<string,string> $fields */
    private function post(string $url, array $fields): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        $_POST = $fields;
        $_GET = [];
        try {
            return $kernel->handle(Request::create($url, 'POST', $fields));
        } finally {
            $kernel->shutdown();
            $_POST = [];
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
        $entries = $page->query('//nav[contains(@class, "cl-course-outline")]//*[contains(@class, "cl-course-outline-item")]');
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
