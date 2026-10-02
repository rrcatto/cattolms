<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\LearningService;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Course\ResourceUnavailable;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Downloadable File Course Items: backed by one Resource Library file, reusable across courses, and
 * served only to a started, entitled learner at an unlocked placement of the right course.
 */
final class DownloadableFileIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private CourseItemService $items;
    private CourseItemRepository $records;
    private LearningService $learning;
    private CourseItemRenderer $renderer;
    private ResourceLibraryService $resources;
    private string $storage;
    private string $suffix;
    private int $owner;
    private int $courseA;
    private int $courseB;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $boot = CliBootstrap::boot();
        $container = $boot['container'];
        $this->db = $container->get(Database::class);
        $this->items = $container->get(CourseItemService::class);
        $this->records = $container->get(CourseItemRepository::class);
        $this->learning = $container->get(LearningService::class);
        $this->renderer = $container->get(CourseItemRenderer::class);
        $this->storage = $boot['instance_root'] . '/storage';
        // The real storage root, as LearningService resolves paths against it; uploads are accepted
        // from the CLI, where PHP's is_uploaded_file() is always false.
        $this->resources = new ResourceLibraryService($this->records, $this->storage, static fn(string $path): bool => is_file($path));
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->suffix = $this->fixture->suffix();
        $this->owner = $this->fixture->createUser('Download author ' . $this->suffix);
        $company = $this->fixture->createCompany($this->owner, 'Downloads ' . $this->suffix, $this->suffix . '.example.test');
        $this->courseA = $this->fixture->createCourse($this->owner, $company, 'downloads-a-' . $this->suffix, 'Downloads A ' . $this->suffix);
        $this->courseB = $this->fixture->createCourse($this->owner, $company, 'downloads-b-' . $this->suffix, 'Downloads B ' . $this->suffix);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
        foreach ($this->files as $file) { if (is_file($file)) { unlink($file); } }
    }

    public function testUploadingAZipAddsOneArchiveResourceToTheLibrary(): void
    {
        $resource = $this->uploadZip('templates-' . $this->suffix . '.zip');
        $row = $this->records->resource($resource);
        self::assertNotNull($row);
        self::assertSame('archive', $row['resource_type']);
        self::assertSame('application/zip', $row['mime_type']);
        self::assertSame('templates-' . $this->suffix . '.zip', $row['original_filename']);
        self::assertFileExists($this->resources->path($row));
        self::assertStringStartsWith($this->storage . '/course-resources/', $this->resources->path($row), 'Resources stay outside the public web root.');
        self::assertSame('archive', ResourceLibraryService::classify('pack.ZIP'));
        self::assertSame('file', ResourceLibraryService::classify('model.stl'));
    }

    public function testAnExistingResourceBacksAReusableDownloadableFileItemAcrossCourses(): void
    {
        $resource = $this->uploadZip('pack-' . $this->suffix . '.zip');
        $item = $this->downloadItem('pack', $resource);
        self::assertSame('downloadable_file', $this->items->item($item)['item_type']);
        $nodeA = $this->items->addExisting($this->courseA, $item, [], $this->owner);
        $nodeB = $this->items->addExisting($this->courseB, $item, [], $this->owner);
        self::assertCount(1, glob($this->storage . '/course-resources/pack-' . $this->suffix . '*') ?: [], 'One stored file, however many courses use it.');

        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);
        $file = $this->learning->download($learner, 'downloads-a-' . $this->suffix, $nodeA, 'pack-' . $this->suffix);
        self::assertSame('pack-' . $this->suffix . '.zip', $file['filename']);
        self::assertSame('application/zip', $file['mime_type']);
        self::assertFileExists($file['path']);

        $other = $this->startedLearner($this->courseB, 'downloads-b-' . $this->suffix);
        self::assertSame($file['path'], $this->learning->download($other, 'downloads-b-' . $this->suffix, $nodeB, 'pack-' . $this->suffix)['path']);
    }

    public function testDownloadsAreRefusedWithoutEntitlementOutsideTheCourseOrBeforeStarting(): void
    {
        $item = $this->downloadItem('guarded', $this->uploadZip('guarded-' . $this->suffix . '.zip'));
        $nodeA = $this->items->addExisting($this->courseA, $item, [], $this->owner);
        $nodeB = $this->items->addExisting($this->courseB, $item, [], $this->owner);
        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);

        $this->assertRefused(fn() => $this->learning->download($learner, 'downloads-b-' . $this->suffix, $nodeB, 'guarded-' . $this->suffix), 'not in your library');
        $this->assertRefused(fn() => $this->learning->download($learner, 'downloads-a-' . $this->suffix, $nodeB, 'guarded-' . $this->suffix), 'not part of this course');
        $this->assertRefused(fn() => $this->learning->download($learner, 'downloads-a-' . $this->suffix, $nodeA, 'other-key-' . $this->suffix), 'not part of this course');

        $stranger = $this->fixture->createUser('Stranger ' . $this->suffix);
        $this->assertRefused(fn() => $this->learning->download($stranger, 'downloads-a-' . $this->suffix, $nodeA, 'guarded-' . $this->suffix), 'not in your library');

        $notStarted = $this->fixture->createUser('Not started ' . $this->suffix);
        $this->fixture->createEnrolment($notStarted, $this->courseA, $this->owner);
        $this->assertRefused(fn() => $this->learning->download($notStarted, 'downloads-a-' . $this->suffix, $nodeA, 'guarded-' . $this->suffix), 'Start the course');
    }

    public function testADripFedPlacementIsRefusedUntilItUnlocks(): void
    {
        $item = $this->downloadItem('later', $this->uploadZip('later-' . $this->suffix . '.zip'));
        $node = $this->items->addExisting($this->courseA, $item, ['delay_days' => 1], $this->owner);
        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);
        $this->assertRefused(fn() => $this->learning->download($learner, 'downloads-a-' . $this->suffix, $node, 'later-' . $this->suffix), 'not available yet');
        $this->items->updatePlacement($this->courseA, $node, ['relative_delay_minutes' => 0], $this->owner);
        self::assertSame('later-' . $this->suffix . '.zip', $this->learning->download($learner, 'downloads-a-' . $this->suffix, $node, 'later-' . $this->suffix)['filename']);
    }

    public function testAMissingResourceOrFileIsReportedAsUnavailable(): void
    {
        $resource = $this->uploadZip('gone-' . $this->suffix . '.zip');
        $item = $this->downloadItem('gone', $resource);
        $node = $this->items->addExisting($this->courseA, $item, [], $this->owner);
        $empty = $this->items->create(['item_key' => 'empty-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'Empty'], $this->owner);
        $emptyNode = $this->items->addExisting($this->courseA, $empty, [], $this->owner);
        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);

        unlink($this->resources->path((array) $this->records->resource($resource)));
        foreach ([[$node, 'gone-'], [$emptyNode, 'empty-']] as [$nodeId, $key]) {
            try {
                $this->learning->download($learner, 'downloads-a-' . $this->suffix, $nodeId, $key . $this->suffix);
                self::fail('A missing file was served.');
            } catch (ResourceUnavailable $exception) {
                self::assertStringContainsString('missing', $exception->getMessage());
            }
        }
        self::assertContains('Course Item “Empty” needs a Resource.', $this->items->publicationValidation($this->courseA)['errors']);
    }

    public function testAReferencedResourceCannotBeDeletedAndIsNotServedByTheGenericRoute(): void
    {
        $resource = $this->uploadZip('kept-' . $this->suffix . '.zip');
        $item = $this->downloadItem('kept', $resource);
        $this->items->addExisting($this->courseA, $item, [], $this->owner);
        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);
        try {
            $this->resources->delete($resource);
            self::fail('A referenced Resource was deleted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Course Item reference', $exception->getMessage());
        }
        self::assertFalse($this->items->canAccessResource($resource, $learner, false), 'Downloadable Files go through the placement-scoped route only.');
    }

    public function testTheLearnerCardAndAnEmbeddedDownloadUseThePlacementRoute(): void
    {
        $resource = $this->uploadZip('embedded-' . $this->suffix . '.zip');
        $item = $this->downloadItem('embedded', $resource);
        $lesson = $this->items->create(['item_key' => 'host-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Host', 'content_source' => '<p>Before</p>[course-item:embedded-' . $this->suffix . ']'], $this->owner);
        $hostNode = $this->items->addExisting($this->courseA, $lesson, [], $this->owner);
        $html = $this->renderer->render($this->items->item($lesson), 'downloads-a-' . $this->suffix, $hostNode);
        self::assertStringContainsString('href="/learn/downloads-a-' . $this->suffix . '/item/' . $hostNode . '/download/embedded-' . $this->suffix . '"', $html);
        self::assertStringContainsString('embedded-' . $this->suffix . '.zip · ZIP archive · ', $html);
        self::assertStringContainsString('<h3 class="cl-course-item-download-title">Templates and Precedents</h3>', $html);
        self::assertStringNotContainsString('/course-resources/', $html);
        self::assertStringNotContainsString('href=', $this->renderer->render($this->items->item($item), 'downloads-a-' . $this->suffix, $hostNode, true), 'A public preview offers no link.');

        $learner = $this->startedLearner($this->courseA, 'downloads-a-' . $this->suffix);
        self::assertSame('embedded-' . $this->suffix . '.zip', $this->learning->download($learner, 'downloads-a-' . $this->suffix, $hostNode, 'embedded-' . $this->suffix)['filename']);
    }

    public function testASignedOutRequestToTheDownloadRouteGetsNoFile(): void
    {
        $boot = CliBootstrap::boot();
        $kernel = new \CattoLearning\Kernel('test', true, $boot['instance_root']);
        $before = $_COOKIE;
        $_COOKIE = [];
        try {
            $response = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/learn/downloads-a-' . $this->suffix . '/item/1/download/pack-' . $this->suffix, 'GET'));
            self::assertNotSame(200, $response->getStatusCode());
            self::assertNull($response->headers->get('Content-Disposition'));
            self::assertStringContainsString('/login', (string) $response->headers->get('Location'), 'A signed-out visitor is sent to sign in.');
        } finally {
            $_COOKIE = $before;
            $kernel->shutdown();
        }
    }

    private function uploadZip(string $name): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($temporary);
        $zip = new \ZipArchive();
        $zip->open($temporary, \ZipArchive::OVERWRITE);
        $zip->addFromString('precedent.txt', str_repeat('Deed of transfer. ', 200));
        $zip->close();
        $this->files[] = $this->storage . '/course-resources/' . $name;
        try {
            return $this->resources->upload(
                ['name' => $name, 'tmp_name' => $temporary, 'size' => filesize($temporary), 'error' => UPLOAD_ERR_OK],
                ['title' => 'Resource ' . $name, 'resource_type' => ResourceLibraryService::classify($name), 'original_filename' => $name],
                $this->owner
            );
        } finally {
            unlink($temporary);
        }
    }

    private function downloadItem(string $key, int $resource): int
    {
        return $this->items->create(['item_key' => $key . '-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'Templates and Precedents', 'description_html' => '<p>Download the template pack used in this course.</p>', 'resource_id' => (string) $resource], $this->owner);
    }

    private function startedLearner(int $courseId, string $slug): int
    {
        $learner = $this->fixture->createUser('Learner ' . $slug);
        $this->fixture->createEnrolment($learner, $courseId, $this->owner, 31536000);
        $this->learning->start($learner, $slug);
        return $learner;
    }

    private function assertRefused(callable $download, string $message): void
    {
        try {
            $download();
            self::fail('The download was served.');
        } catch (ResourceUnavailable $exception) {
            self::fail('Expected an access refusal, got: ' . $exception->getMessage());
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
