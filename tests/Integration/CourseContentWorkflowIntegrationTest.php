<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The Course Content workflow: create-and-attach from the Course Editor, add existing, create
 * unassigned in the library, and Resource-backed items that choose or upload a Resource Library file.
 */
final class CourseContentWorkflowIntegrationTest extends TestCase
{
    private Database $db;
    private CourseItemService $items;
    private CourseItemRepository $records;
    private ResourceLibraryService $resources;
    private string $storage;
    private string $suffix;
    private int $owner;
    private int $course;
    private int $otherCourse;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $boot = CliBootstrap::boot();
        $container = $boot['container'];
        $this->db = $container->get(Database::class);
        $this->items = $container->get(CourseItemService::class);
        $this->records = $container->get(CourseItemRepository::class);
        $this->storage = $boot['instance_root'] . '/storage';
        $this->resources = new ResourceLibraryService($this->records, $this->storage, static fn(string $path): bool => is_file($path));
        $this->db->beginTransaction();
        $fixture = new DevelopmentFixture($this->db);
        $this->suffix = $fixture->suffix();
        $this->owner = $fixture->createUser('Workflow author ' . $this->suffix);
        $company = $fixture->createCompany($this->owner, 'Workflow ' . $this->suffix, $this->suffix . '.example.test');
        $this->course = $fixture->createCourse($this->owner, $company, 'workflow-' . $this->suffix, 'Workflow ' . $this->suffix);
        $this->otherCourse = $fixture->createCourse($this->owner, $company, 'workflow-other-' . $this->suffix, 'Workflow other ' . $this->suffix);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
        foreach ($this->files as $file) { if (is_file($file)) { unlink($file); } }
    }

    public function testCreatingFromTheCourseEditorAttachesTheItemInPlaceWithItsAvailability(): void
    {
        $first = $this->items->createAttached($this->course, $this->lessonInput('first'), $this->owner);
        $section = $this->items->addSection($this->course, ['title' => 'Module ' . $this->suffix], $this->owner);
        $resource = $this->upload('pack-' . $this->suffix . '.zip');
        $created = $this->items->createAttached($this->course, [
            'item_key' => 'pack-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'Templates and Precedents',
            'resource_id' => (string) $resource, 'parent_node_id' => (string) $section, 'delay_days' => '2',
        ], $this->owner);

        $rows = $this->records->structure($this->course);
        $byItem = [];
        foreach ($rows as $row) { if ($row['node_type'] === 'item') { $byItem[(int) $row['course_item_id']] = $row; } }
        self::assertArrayHasKey($created, $byItem, 'The new item is attached to the current course.');
        self::assertSame($section, (int) $byItem[$created]['parent_node_id'], 'It lands in the chosen section.');
        self::assertSame(2880, (int) $byItem[$created]['relative_delay_minutes'], 'Its drip delay is kept.');
        self::assertSame($resource, (int) $byItem[$created]['resource_id']);
        self::assertSame([$first, $section], [(int) $rows[0]['course_item_id'], (int) $rows[1]['id']], 'Existing ordering is unchanged.');
    }

    public function testAddingAnExistingItemStillSharesIt(): void
    {
        $id = $this->items->create($this->lessonInput('shared'), $this->owner);
        $this->items->addExisting($this->course, $id, [], $this->owner);
        $this->items->addExisting($this->otherCourse, $id, [], $this->owner);
        self::assertCount(2, array_filter($this->items->item($id)['usage'], static fn(array $usage): bool => ($usage['usage_type'] ?? '') === 'placement'));
    }

    public function testAnItemCreatedInTheLibraryIsUnassigned(): void
    {
        $id = $this->items->create($this->lessonInput('unassigned'), $this->owner);
        $unused = array_map(static fn(array $item): int => (int) $item['id'], $this->items->library('unassigned-' . $this->suffix)['unused']);
        self::assertSame([$id], $unused);
    }

    public function testResourceReferencesMustExistAndSuitTheItemType(): void
    {
        $zip = $this->upload('refs-' . $this->suffix . '.zip');
        $this->expectRefusal(['item_type' => 'downloadable_file', 'resource_id' => '999999999'], 'exists in the Resource Library');
        $this->expectRefusal(['item_type' => 'pdf', 'resource_id' => (string) $zip], 'cannot use a archive Resource');
        $this->expectRefusal(['item_type' => 'html_lesson', 'resource_id' => (string) $zip], 'do not use a Resource');
        self::assertSame(['downloadable_file'], CourseItemService::itemTypesForResource('archive'));
        self::assertSame(['pdf', 'downloadable_file'], CourseItemService::itemTypesForResource('pdf'));
        self::assertSame(['image_graphic', 'downloadable_file'], CourseItemService::itemTypesForResource('image_graphic'));
        self::assertSame('pdf', CourseItemService::resourceTypeForUpload('pdf', 'notes.bin'));
        self::assertSame('archive', CourseItemService::resourceTypeForUpload('downloadable_file', 'pack.zip'));
    }

    public function testAnUploadedResourceIsSelectedAndOneFileIsReusedAcrossItemsAndCourses(): void
    {
        $resource = $this->upload('reuse-' . $this->suffix . '.zip');
        self::assertSame('archive', $this->records->resource($resource)['resource_type'] ?? null);
        $one = $this->items->createAttached($this->course, ['item_key' => 'reuse-one-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'One', 'resource_id' => (string) $resource], $this->owner);
        $two = $this->items->createAttached($this->otherCourse, ['item_key' => 'reuse-two-' . $this->suffix, 'item_type' => 'downloadable_file', 'title' => 'Two', 'resource_id' => (string) $resource], $this->owner);
        self::assertSame($resource, (int) $this->items->item($one)['resource_id']);
        self::assertSame($resource, (int) $this->items->item($two)['resource_id']);
        self::assertSame(2, (int) ($this->records->resource($resource)['usage_count'] ?? 0));
        self::assertCount(1, glob($this->storage . '/course-resources/reuse-' . $this->suffix . '*') ?: [], 'No per-course copies.');
    }

    public function testInUseResourcesAndItemsAreProtectedAndRetypingMustStillSuit(): void
    {
        $pdf = $this->upload('guide-' . $this->suffix . '.pdf', 'pdf', '%PDF-1.4 minimal');
        $id = $this->items->createAttached($this->course, ['item_key' => 'guide-' . $this->suffix, 'item_type' => 'pdf', 'title' => 'Guide', 'resource_id' => (string) $pdf], $this->owner);
        try { $this->resources->delete($pdf); self::fail('A used Resource was deleted.'); }
        catch (InvalidArgumentException $exception) { self::assertStringContainsString('Course Item reference', $exception->getMessage()); }
        try { $this->items->delete($id, $this->owner); self::fail('A placed Course Item was deleted.'); }
        catch (InvalidArgumentException $exception) { self::assertNotSame('', $exception->getMessage()); }
        try { $this->resources->update($pdf, ['title' => 'Guide file', 'resource_type' => 'archive']); self::fail('A PDF item was left on an archive.'); }
        catch (InvalidArgumentException $exception) { self::assertStringContainsString('cannot use a archive Resource', $exception->getMessage()); }
        $this->resources->update($pdf, ['title' => 'Guide file ' . $this->suffix, 'resource_type' => 'pdf', 'description' => 'Renamed']);
        self::assertSame('Guide file ' . $this->suffix, $this->records->resource($pdf)['title'] ?? null);
    }

    /** @return array<string,string> */
    private function lessonInput(string $name): array
    {
        return ['item_key' => $name . '-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => ucfirst($name), 'content_source' => '<p>' . $name . '</p>'];
    }

    /** @param array<string,string> $input */
    private function expectRefusal(array $input, string $message): void
    {
        try {
            $this->items->create($input + ['item_key' => 'refused-' . bin2hex(random_bytes(3)) . '-' . $this->suffix, 'title' => 'Refused'], $this->owner);
            self::fail('An invalid Resource reference was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function upload(string $name, ?string $type = null, ?string $content = null): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'res');
        self::assertIsString($temporary);
        if ($content === null) {
            $zip = new \ZipArchive();
            $zip->open($temporary, \ZipArchive::OVERWRITE);
            $zip->addFromString('template.txt', 'Template');
            $zip->close();
        } else {
            file_put_contents($temporary, $content);
        }
        $this->files[] = $this->storage . '/course-resources/' . $name;
        try {
            return $this->resources->upload(
                ['name' => $name, 'tmp_name' => $temporary, 'size' => filesize($temporary), 'error' => UPLOAD_ERR_OK],
                ['title' => 'Resource ' . $name, 'resource_type' => $type ?? CourseItemService::resourceTypeForUpload('downloadable_file', $name), 'original_filename' => $name],
                $this->owner
            );
        } finally {
            unlink($temporary);
        }
    }
}
