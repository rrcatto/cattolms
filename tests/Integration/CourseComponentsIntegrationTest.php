<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\AssessmentService;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\LearningService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CourseComponentsIntegrationTest extends TestCase
{
    private Database $db;
    private CourseItemRenderer $renderer;
    private LearningService $learning;
    private AssessmentService $assessments;
    private CourseItemService $items;
    private CourseItemRepository $records;
    private int $owner;
    private int $course;
    private int $secondCourse;
    private string $suffix;

    protected function setUp(): void
    {
        $container = CliBootstrap::boot()['container'];
        $this->db = $container->get(Database::class);
        $this->renderer = $container->get(CourseItemRenderer::class);
        $this->learning = $container->get(LearningService::class);
        $this->assessments = $container->get(AssessmentService::class);
        $this->items = $container->get(CourseItemService::class);
        $this->records = $container->get(CourseItemRepository::class);
        $this->db->beginTransaction();
        $fixture = new DevelopmentFixture($this->db);
        $this->suffix = $fixture->suffix();
        $this->owner = $fixture->createUser('Component author ' . $this->suffix);
        $company = $fixture->createCompany($this->owner, 'Components ' . $this->suffix, $this->suffix . '.example.test');
        $this->course = $fixture->createCourse($this->owner, $company, 'components-' . $this->suffix, 'Components ' . $this->suffix);
        $this->secondCourse = $fixture->createCourse($this->owner, $company, 'other-' . $this->suffix, 'Other ' . $this->suffix);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) { $this->db->rollBack(); }
    }

    private function lesson(string $name, string $source = '<p>Lesson</p>'): int
    {
        return $this->items->create(['item_key' => $name . '-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => $name, 'content_source' => $source], $this->owner);
    }

    private function assessment(string $name): int
    {
        return $this->items->create(['item_key' => $name . '-' . $this->suffix, 'item_type' => 'assessment', 'title' => $name], $this->owner);
    }

    public function testSharedSaveAndIndependentSaveAsKeepPlacementOverrides(): void
    {
        $id = $this->lesson('shared');
        $node = $this->items->addExisting($this->course, $id, ['display_title_override' => 'Course-specific title'], $this->owner);
        $this->items->addExisting($this->secondCourse, $id, [], $this->owner);
        $input = $this->items->item($id);
        $input['content_source'] = '<p>Changed everywhere</p>';
        $this->items->update($id, $input, $this->owner);
        self::assertSame('Course-specific title', $this->records->placement($this->course, $node)['display_title_override']);
        self::assertSame($input['content_source'], $this->records->structure($this->secondCourse)[0]['content_source']);
        $copy = $this->items->saveAs($id, ['item_key' => 'copy-' . $this->suffix, 'title' => 'Independent'], $this->owner);
        $copyData = $this->items->item($copy);
        $copyData['content_source'] = 'Copy changed';
        $this->items->update($copy, $copyData, $this->owner);
        self::assertSame('<p>Changed everywhere</p>', $this->items->item($id)['content_source']);
        self::assertCount(2, $this->items->item($id)['usage']);
    }

    public function testRenameLeavesSourceUntouchedAndDraftReferenceBlocksPublication(): void
    {
        $target = $this->lesson('target');
        $key = $this->items->item($target)['item_key'];
        $source = '<p>Before</p>[course-item:' . $key . ']';
        $host = $this->lesson('host', $source);
        $this->items->addExisting($this->course, $host, [], $this->owner);
        $data = $this->items->item($target);
        $data['item_key'] = 'renamed-' . $this->suffix;
        $this->items->update($target, $data, $this->owner);
        self::assertSame($source, $this->items->item($host)['content_source']);
        self::assertStringContainsString('Unresolved shortcode', implode(' ', $this->items->publicationValidation($this->course)['errors']));
        $this->items->create(['item_key' => $key, 'item_type' => 'html_lesson', 'title' => 'Explicit replacement', 'content_source' => '<p>Replacement</p>'], $this->owner);
        self::assertSame([], $this->items->publicationValidation($this->course)['errors']);
        $renderer = $this->renderer;
        self::assertStringContainsString('Replacement', $renderer->render($this->items->item($host), 'components-' . $this->suffix, 1));
        self::assertSame($source, $this->items->item($host)['content_source']);
    }

    public function testEmbeddedReferenceProtectsDeletionWithoutStandalonePlacement(): void
    {
        $target = $this->lesson('target');
        $this->lesson('host', '[course-item:target-' . $this->suffix . ']');
        self::assertSame('embedded', $this->items->item($target)['usage'][0]['usage_type']);
        $this->expectException(InvalidArgumentException::class);
        $this->items->delete($target, $this->owner);
    }

    public function testRemovingLastPlacementKeepsItemInUnusedLibraryGroup(): void
    {
        $id = $this->lesson('orphan');
        $node = $this->items->addExisting($this->course, $id, [], $this->owner);
        $this->items->removeFromCourse($this->course, $node, $this->owner);
        self::assertNotNull($this->records->item($id));
        $library = $this->items->library('orphan-' . $this->suffix);
        self::assertSame([], $library['groups']);
        self::assertSame($id, (int) $library['unused'][0]['id']);
    }

    public function testCumulativeAvailabilityAndScheduledSectionOverride(): void
    {
        $id = $this->lesson('timing');
        $this->items->addExisting($this->course, $id, [], $this->owner);
        $section = $this->items->addSection($this->course, ['title' => 'Week two', 'delay_weeks' => 1], $this->owner);
        $this->items->addExisting($this->course, $id, ['parent_node_id' => $section, 'delay_days' => 3], $this->owner);
        $this->items->addExisting($this->course, $id, ['delay_days' => 2], $this->owner);
        $rows = $this->items->availability($this->course, gmdate(DATE_ATOM), false);
        self::assertSame([0,10080,10080,12960], array_column($rows, 'unlock_minutes'));
        self::assertSame('01 weeks 02 days 00 hours 00 minutes', CourseItemService::duration(12960));
        self::assertFalse($rows[0]['is_locked']);
        self::assertTrue($rows[2]['is_locked']);
    }

    public function testReviewStudyAidAndFinalAssessmentDoNotReceiveModuleNumbers(): void
    {
        $module = $this->lesson('ordinary-module');
        $moduleAssessment = $this->assessment('ordinary-module-assessment');
        $review = $this->items->create(['item_key' => 'review-' . $this->suffix, 'item_type' => 'html_lesson', 'title' => 'Review Study Aid', 'type_config' => ['presentation_role' => 'review_study_aid']], $this->owner);
        $final = $this->assessment('integrated-final-assessment');
        $this->items->addExisting($this->course, $module, [], $this->owner);
        $this->items->addExisting($this->course, $moduleAssessment, ['assessment_role' => 'graded'], $this->owner);
        $this->items->addExisting($this->course, $review, [], $this->owner);
        $this->items->addExisting($this->course, $final, ['assessment_role' => 'final'], $this->owner);

        $rows = $this->items->availability($this->course, null, false);
        self::assertSame([1, null, null, null], array_column($rows, 'module_number'));
        self::assertSame([null, 1, null, null], array_column($rows, 'assessment_module_number'));
        self::assertTrue($rows[2]['is_review_study_aid']);
        self::assertTrue($rows[3]['is_final_assessment']);
    }

    public function testAvailabilityCanBeChangedWhileADevelopmentTesterIsActive(): void
    {
        $id = $this->lesson('locked');
        $node = $this->items->addExisting($this->course, $id, ['delay_days' => 1], $this->owner);
        $fixture = new DevelopmentFixture($this->db);
        $enrolment = $fixture->createEnrolment($this->owner, $this->course, $this->owner, 31536000, false, 'active');
        $this->db->executeStatement('UPDATE course_enrolments SET started_at=NOW(),expires_at=NOW()+INTERVAL \'1 year\' WHERE id=:id', ['id' => $enrolment]);
        $this->items->updatePlacement($this->course, $node, ['display_title_override' => 'Allowed title edit'], $this->owner);
        self::assertSame(1440, (int) $this->records->node($this->course, $node)['relative_delay_minutes']);
        $this->items->updatePlacement($this->course, $node, ['relative_delay_minutes' => 0], $this->owner);
        self::assertSame(0, (int) $this->records->node($this->course, $node)['relative_delay_minutes']);
    }

    public function testReplacingAStartedTestCourseRemovesItsGrantAndCourseWork(): void
    {
        $itemId = $this->lesson('before-reimport');
        $this->items->addExisting($this->course, $itemId, [], $this->owner);
        $fixture = new DevelopmentFixture($this->db);
        $enrolmentId = $fixture->createEnrolment($this->owner, $this->course, $this->owner, 86400, false, 'active');
        $this->db->executeStatement("UPDATE course_enrolments SET started_at=NOW(),expires_at=NOW()+INTERVAL '1 day' WHERE id=:id", ['id' => $enrolmentId]);
        (new \CattoLearning\Course\CoursePortabilityRepository($this->db))->resetCourseContent($this->course);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_enrolments WHERE course_id=:id', ['id' => $this->course]));
        self::assertSame([], $this->records->structure($this->course));
        self::assertNotNull($this->db->fetchOne('SELECT id FROM courses WHERE id=:id', ['id' => $this->course]));
    }

    public function testReplacementClearsPurchasedCourseWorkWithoutDeletingItsEntitlement(): void
    {
        $itemId = $this->lesson('purchased-before-reimport');
        $this->items->addExisting($this->course, $itemId, [], $this->owner);
        $fixture = new DevelopmentFixture($this->db);
        $enrolmentId = $fixture->createEnrolment($this->owner, $this->course, $this->owner, 86400, false, 'completed');
        $this->db->executeStatement("UPDATE course_enrolments SET started_at=NOW(),expires_at=NOW()+INTERVAL '1 day',completed_at=NOW() WHERE id=:id", ['id' => $enrolmentId]);
        $this->db->executeStatement("INSERT INTO commerce_entitlements(enrolment_id,state,source,snapshot,created_at,activation_deadline_at,access_started_at,access_expires_at) VALUES (:id,'active','free','{}'::jsonb,NOW(),NOW()+INTERVAL '1 day',NOW(),NOW()+INTERVAL '1 day')", ['id' => $enrolmentId]);
        $this->db->executeStatement("INSERT INTO course_results(enrolment_id,module_percentage,final_percentage,overall_percentage,grade_code,passed) VALUES (:id,50,50,50,'PASS',TRUE)", ['id' => $enrolmentId]);

        (new \CattoLearning\Course\CoursePortabilityRepository($this->db))->resetCourseContent($this->course);

        self::assertSame('active', $this->db->fetchOne('SELECT status FROM course_enrolments WHERE id=:id', ['id' => $enrolmentId]));
        self::assertNotNull($this->db->fetchOne('SELECT id FROM commerce_entitlements WHERE enrolment_id=:id', ['id' => $enrolmentId]));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_results WHERE enrolment_id=:id', ['id' => $enrolmentId]));
    }

    public function testCycleIsPublicationErrorAndRendererTerminates(): void
    {
        $id = $this->lesson('cycle', '[course-item:cycle-' . $this->suffix . ']');
        $this->items->addExisting($this->course, $id, [], $this->owner);
        self::assertStringContainsString('Circular', implode(' ', $this->items->publicationValidation($this->course)['errors']));
        $renderer = $this->renderer;
        self::assertStringContainsString('Circular', $renderer->render($this->items->item($id), 'components-' . $this->suffix, 1));
    }

    public function testEmbeddedAssessmentHasItsOwnIdentityAndCountsOncePerContext(): void
    {
        $fixture = new DevelopmentFixture($this->db);
        $assessment = $fixture->createAssessmentItem($this->course, $this->owner);
        $fixture->createQuestion($assessment['item_id']);
        $this->items->removeFromCourse($this->course, $assessment['node_id'], $this->owner);
        $id = $this->lesson('host', '[course-item:' . $assessment['key'] . '][course-item:' . $assessment['key'] . ']');
        $node = $this->items->addExisting($this->course, $id, [], $this->owner);
        $enrolment = $fixture->createEnrolment($this->owner, $this->course, $this->owner);
        self::assertSame(1, $this->records->assessmentProgress($enrolment, $this->course)['total']);
        $this->learning->start($this->owner, 'components-' . $this->suffix);
        $overview = $this->assessments->overview($this->owner, 'components-' . $this->suffix, $node, $assessment['key']);
        self::assertSame($assessment['item_id'], $overview['assessment']['id']);
        self::assertSame('30 mins', $overview['time_limit_label']);
    }
    public function testAssessmentEditsPreserveQuestionAndOptionIdentityAfterSubmission(): void
    {
        $fixture = new DevelopmentFixture($this->db);
        $assessment = $fixture->createAssessmentItem($this->course, $this->owner);
        $question = $fixture->createQuestion($assessment['item_id']);
        $fixture->createEnrolment($this->owner, $this->course, $this->owner);
        $slug = 'components-' . $this->suffix;
        $this->learning->start($this->owner, $slug);
        $session = $this->assessments->startAttempt($this->owner, $slug, $assessment['node_id'], $assessment['key'], 'graded');
        $this->assessments->respond($this->owner, (string) $session['public_id'], $question['question_id'], $question['correct_option_id'], false);
        $input = $this->items->item($assessment['item_id']);
        $input['questions'][0]['question_html'] = '<p>Revised wording</p>';
        $this->items->update($assessment['item_id'], $input, $this->owner);
        $saved = $this->items->item($assessment['item_id']);
        self::assertSame($question['question_id'], (int) $saved['questions'][0]['id']);
        self::assertSame($question['correct_option_id'], (int) $saved['questions'][0]['options'][0]['id']);
        self::assertSame('<p>Revised wording</p>', $saved['questions'][0]['question_html']);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM assessment_attempts WHERE course_item_id=:id', ['id' => $assessment['item_id']]));
        $copy = $this->items->saveAs($assessment['item_id'], ['item_key' => 'assessment-copy-' . $this->suffix, 'title' => 'Independent assessment'], $this->owner);
        self::assertNotSame($question['question_id'], (int) $this->items->item($copy)['questions'][0]['id']);
    }

    public function testMovingNestedSectionCannotExceedThreeSectionLevels(): void
    {
        $first = $this->items->addSection($this->course, ['title' => 'First'], $this->owner);
        $nested = $this->items->addSection($this->course, ['title' => 'Nested', 'parent_node_id' => $first], $this->owner);
        $this->items->addSection($this->course, ['title' => 'Third', 'parent_node_id' => $nested], $this->owner);
        $preceding = $this->items->addSection($this->course, ['title' => 'Preceding'], $this->owner);
        $this->items->move($this->course, $preceding, 'up', $this->owner);
        $this->expectException(InvalidArgumentException::class);
        $this->items->move($this->course, $first, 'indent', $this->owner);
    }

    public function testArrangementIsOnlyPersistedOnSaveAndUnindentStaysBesideItsModule(): void
    {
        $module = $this->items->addExisting($this->course, $this->lesson('module-one'), [], $this->owner);
        $assessment = $this->items->addExisting($this->course, $this->assessment('module-one-assessment'), ['assessment_role' => 'graded'], $this->owner);
        $nextModule = $this->items->addExisting($this->course, $this->lesson('module-two'), [], $this->owner);
        $baseline = $this->items->currentStructureSignature($this->course);
        $draft = $this->items->moveDraft($this->items->structureDraft($this->course), $assessment, 'indent');
        self::assertNull($this->records->node($this->course, $assessment)['parent_node_id']);
        self::assertSame($module, $this->items->previewStructureDraft($this->course, $draft)['structure'][1]['parent_node_id']);
        $this->items->saveStructureDraft($this->course, $draft, $baseline, $this->owner);
        self::assertSame($module, (int) $this->records->node($this->course, $assessment)['parent_node_id']);

        $draft = $this->items->moveDraft($this->items->structureDraft($this->course), $assessment, 'unindent');
        self::assertSame([$module, $assessment, $nextModule], array_column($draft, 'id'));
        self::assertSame($module, (int) $this->records->node($this->course, $assessment)['parent_node_id']);
        $this->items->saveStructureDraft($this->course, $draft, $this->items->currentStructureSignature($this->course), $this->owner);
        self::assertSame([$module, $assessment, $nextModule], array_column($this->records->structure($this->course), 'id'));
        self::assertNull($this->records->node($this->course, $assessment)['parent_node_id']);
    }

    public function testPublicAssessmentPreviewDoesNotCreateActivityRecords(): void
    {
        $fixture = new DevelopmentFixture($this->db);
        $assessment = $fixture->createAssessmentItem($this->course, $this->owner);
        $question = $fixture->createQuestion($assessment['item_id']);
        $this->db->executeStatement("UPDATE courses SET status='published' WHERE id=:id", ['id' => $this->course]);
        $this->items->updatePlacement($this->course, $assessment['node_id'], ['public_preview' => true], $this->owner);
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM assessment_sessions');
        $result = $this->assessments->publicPreview('components-' . $this->suffix, $assessment['node_id'], $assessment['key'], [$question['question_id'] => $question['correct_option_id']]);
        self::assertSame(100.0, $result['percentage']);
        self::assertTrue($result['submitted']);
        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM assessment_sessions'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM course_enrolments WHERE course_id=:id', ['id' => $this->course]));
    }

    public function testMixedCaseKeyIsPreservedAndResolvedExactly(): void
    {
        $key = 'YouTube-XyZ_' . $this->suffix;
        $id = $this->items->create(['item_key' => $key, 'item_type' => 'html_lesson', 'title' => 'Mixed case', 'content_source' => 'Resolved mixed case'], $this->owner);
        self::assertSame($key, $this->items->item($id)['item_key']);
        $host = $this->lesson('case-host', '[course-item:' . $key . ']');
        self::assertSame('Resolved mixed case', $this->renderer->render($this->items->item($host), 'test', 1));
        self::assertNull($this->records->itemByKey(strtolower($key)));
    }

    public function testResourceBackedSaveAsSharesFileAndDeletionProtectsPosterReferences(): void
    {
        $root = sys_get_temp_dir() . '/component-resource-' . $this->suffix;
        mkdir($root . '/course-resources', 0770, true);
        $path = $root . '/course-resources/diagram.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
        $resources = new \CattoLearning\Course\ResourceLibraryService($this->records, $root);
        try {
            $resource = $resources->register(['filename' => 'diagram.svg', 'title' => 'Diagram ' . $this->suffix, 'resource_type' => 'image_graphic'], $this->owner);
            $id = $this->items->create(['item_key' => 'diagram-' . $this->suffix, 'item_type' => 'image_graphic', 'title' => 'Diagram', 'resource_id' => $resource], $this->owner);
            $copy = $this->items->saveAs($id, ['item_key' => 'diagram-copy-' . $this->suffix, 'title' => 'Copy'], $this->owner);
            self::assertSame($resource, (int) $this->items->item($copy)['resource_id']);
            self::assertCount(1, glob($root . '/course-resources/*') ?: []);
            $poster = $this->items->create(['item_key' => 'video-' . $this->suffix, 'item_type' => 'uploaded_video', 'title' => 'Video draft', 'poster_resource_id' => $resource], $this->owner);
            $this->items->delete($copy, $this->owner);
            $this->items->delete($id, $this->owner);
            self::assertSame(1, (int) $this->records->resource($resource)['usage_count']);
            try { $resources->delete($resource); self::fail('A referenced poster must not be deleted.'); } catch (InvalidArgumentException $error) { self::assertStringContainsString('reference', $error->getMessage()); }
            $this->items->delete($poster, $this->owner);
            $resources->delete($resource);
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) { unlink($path); }
            rmdir($root . '/course-resources'); rmdir($root);
        }
    }

    public function testIncompleteResourceItemSavesAsDraftButBlocksPublication(): void
    {
        $id = $this->items->create(['item_key' => 'draft-pdf-' . $this->suffix, 'item_type' => 'pdf', 'title' => 'Draft PDF'], $this->owner);
        $this->items->addExisting($this->course, $id, [], $this->owner);
        self::assertNotEmpty($this->items->publicationValidation($this->course)['errors']);
    }

}
