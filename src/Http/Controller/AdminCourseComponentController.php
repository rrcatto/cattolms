<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Auth\AuthService;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\CourseService;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\View\ThemeRenderer;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Administration routes for reusable Course Items, course placements and the Resource Library. */
final class AdminCourseComponentController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly CourseItemService $items, private readonly ResourceLibraryService $resources, private readonly CourseService $courses)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/course-items', name: 'admin_course_item_library', methods: ['GET'])]
    public function library(): Response
    {
        $this->requirePermission('COURSE.MANAGEMENT.VIEW');
        return $this->render('admin-course-item-library', ['title' => 'Course Item Library', 'item_library' => $this->items->library(trim((string) ($_GET['q'] ?? '')), trim((string) ($_GET['type'] ?? ''))), 'item_types' => CourseItemService::TYPES]);
    }

    #[Route('/admin/course-items/new', name: 'admin_course_item_new', methods: ['GET'])]
    public function createForm(): Response
    {
        $this->requirePermission('COURSE.EDIT');
        $courseId = max(0, (int) ($_GET['course_id'] ?? 0));
        if ($courseId > 0) { $this->requireManagedCourse($courseId); }
        if (!isset($_GET['type'])) { return $this->render('admin-course-item-type', ['title' => 'Choose Course Item type', 'course_id' => $courseId, 'item_types' => CourseItemService::TYPES]); }
        return $this->itemForm($this->blankItem((string) $_GET['type']), $courseId, '/admin/course-items');
    }

    #[Route('/admin/course-items', name: 'admin_course_item_create', methods: ['POST'])]
    public function create(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = max(0, (int) ($_POST['course_id'] ?? 0));
        if ($courseId > 0) { $this->requireManagedCourse($courseId); }
        if (isset($_POST['question_action'])) { return $this->itemForm($this->items->editorDraft($_POST, $this->blankItem((string) ($_POST['item_type'] ?? 'assessment'))), $courseId, '/admin/course-items'); }
        return $this->handle(function () use ($user, $courseId): void {
            $id = $courseId > 0 ? $this->items->createAttached($courseId, $_POST, $user->id) : $this->items->create($_POST, $user->id);
            $this->flash('success', 'The Course Item was created.');
            $this->redirect($courseId > 0 ? '/admin/courses/' . $courseId . '/content' : '/admin/course-items/' . $id);
        }, $courseId > 0 ? '/admin/course-items/new?course_id=' . $courseId : '/admin/course-items/new');
    }

    #[Route('/admin/course-items/{item_id}', name: 'admin_course_item_edit', requirements: ['item_id' => '\\d+'], methods: ['GET'])]
    public function edit(): Response
    {
        $this->requirePermission('COURSE.EDIT');
        $item = $this->requireManagedItem($this->itemId());
        return $this->itemForm($item, 0, '/admin/course-items/' . $item['id']);
    }

    #[Route('/admin/course-items/{item_id}', name: 'admin_course_item_update', requirements: ['item_id' => '\\d+'], methods: ['POST'])]
    public function update(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $id = $this->itemId(); $this->requireManagedItem($id);
        if (isset($_POST['question_action'])) { return $this->itemForm($this->items->editorDraft($_POST, $this->items->item($id)), 0, '/admin/course-items/' . $id); }
        return $this->handle(function () use ($user, $id): void {
            if (($_POST['item_action'] ?? '') === 'save_as') {
                $input = array_replace($_POST, ['item_key' => (string) ($_POST['copy_key'] ?? ''), 'title' => (string) ($_POST['copy_title'] ?? '')]);
                $copy = $this->items->saveAs($id, $input, $user->id);
                $this->flash('success', 'An independent Course Item was created.');
                $this->redirect('/admin/course-items/' . $copy);
            }
            $this->items->update($id, $_POST, $user->id);
            $this->flash('success', 'The shared Course Item was saved.');
            $this->redirect('/admin/course-items/' . $id);
        }, '/admin/course-items/' . $id);
    }

    #[Route('/admin/course-items/{item_id}/save-as', name: 'admin_course_item_save_as', requirements: ['item_id' => '\\d+'], methods: ['POST'])]
    public function saveAs(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $id = $this->itemId(); $this->requireManagedItem($id);
        return $this->handle(function () use ($user, $id): void { $copy = $this->items->saveAs($id, $_POST, $user->id); $this->flash('success', 'An independent Course Item was created.'); $this->redirect('/admin/course-items/' . $copy); }, '/admin/course-items/' . $id);
    }

    #[Route('/admin/course-items/{item_id}/delete', name: 'admin_course_item_delete', requirements: ['item_id' => '\\d+'], methods: ['POST'])]
    public function delete(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $id = $this->itemId(); $this->requireManagedItem($id);
        return $this->handle(function () use ($user, $id): void { $this->items->delete($id, $user->id); $this->flash('success', 'The unused Course Item was deleted.'); $this->redirect('/admin/course-items'); }, '/admin/course-items/' . $id);
    }

    #[Route('/admin/courses/{id}/content', name: 'admin_course_content', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function content(): Response
    {
        $courseId = $this->courseId(); $this->requirePermission('COURSE.EDIT'); $this->requireManagedCourse($courseId);
        $search = trim((string) ($_GET['q'] ?? ''));
        $library = $this->items->library($search); $libraryItems = [];
        foreach ($library['unused'] as $item) { $libraryItems[(int) $item['id']] = $item; }
        foreach ($library['groups'] as $group) { foreach ($group['items'] as $item) { $libraryItems[(int) $item['id']] = $item; } }
        return $this->render('admin-course-content', ['title' => 'Course Content', 'course' => $this->items->courseContent($courseId), 'library_items' => array_values($libraryItems), 'item_search' => $search]);
    }

    #[Route('/admin/courses/{id}/content/sections', name: 'admin_course_content_section', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addSection(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId);
        return $this->handle(function () use ($user, $courseId): void { $this->items->addSection($courseId, $_POST, $user->id); $this->flash('success', 'The section was added.'); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/placements', name: 'admin_course_content_placement', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addPlacement(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId);
        return $this->handle(function () use ($user, $courseId): void { $this->items->addExisting($courseId, (int) ($_POST['course_item_id'] ?? 0), $_POST, $user->id); $this->flash('success', 'The existing Course Item was added to this course.'); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/{node_id}/placement', name: 'admin_course_content_placement_update', requirements: ['id' => '\\d+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function updatePlacement(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId); $nodeId = $this->nodeId();
        return $this->handle(function () use ($user, $courseId, $nodeId): void { $this->items->updatePlacement($courseId, $nodeId, $_POST, $user->id); $this->flash('success', 'The placement was saved.'); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/{node_id}/section', name: 'admin_course_content_section_update', requirements: ['id' => '\\d+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function updateSection(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId); $nodeId = $this->nodeId();
        return $this->handle(function () use ($user, $courseId, $nodeId): void { $this->items->updateSection($courseId, $nodeId, $_POST, $user->id); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/move-selection', name: 'admin_course_content_move_selection', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function moveSelection(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId);
        return $this->handle(function () use ($user, $courseId): void { $this->items->moveSelection($courseId, array_values(array_map('intval', (array) ($_POST['node_ids'] ?? []))), (string) ($_POST['direction'] ?? ''), $user->id); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/{node_id}/move', name: 'admin_course_content_move', requirements: ['id' => '\\d+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function move(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId); $nodeId = $this->nodeId();
        return $this->handle(function () use ($user, $courseId, $nodeId): void { $this->items->move($courseId, $nodeId, (string) ($_POST['direction'] ?? ''), $user->id); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/courses/{id}/content/{node_id}/remove', name: 'admin_course_content_remove', requirements: ['id' => '\\d+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function remove(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId); $nodeId = $this->nodeId();
        return $this->handle(function () use ($user, $courseId, $nodeId): void { $this->items->removeFromCourse($courseId, $nodeId, $user->id); $this->flash('success', 'The placement was removed. The shared Course Item remains in the library.'); $this->redirect('/admin/courses/' . $courseId . '/content'); }, '/admin/courses/' . $courseId . '/content');
    }

    #[Route('/admin/resources', name: 'admin_resource_library', methods: ['GET'])]
    public function resourceLibrary(): Response
    {
        $this->requirePermission('COURSE.EDIT');
        return $this->render('admin-resource-library', ['title' => 'Resource Library', 'resources' => $this->resources->library(trim((string) ($_GET['q'] ?? '')), trim((string) ($_GET['type'] ?? ''))), 'resource_types' => ResourceLibraryService::TYPES, 'search' => trim((string) ($_GET['q'] ?? '')), 'selected_type' => trim((string) ($_GET['type'] ?? ''))]);
    }

    #[Route('/admin/resources', name: 'admin_resource_upload', methods: ['POST'])]
    public function uploadResource(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT');
        return $this->handle(function () use ($user): void { $this->resources->upload((array) ($_FILES['resource_file'] ?? []), $_POST, $user->id); $this->flash('success', 'The Resource was uploaded.'); $this->redirect('/admin/resources'); }, '/admin/resources');
    }

    #[Route('/admin/resources/register', name: 'admin_resource_register', methods: ['POST'])]
    public function registerResource(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT');
        return $this->handle(function () use ($user): void { $this->resources->register($_POST, $user->id); $this->flash('success', 'The existing Resource file was registered.'); $this->redirect('/admin/resources'); }, '/admin/resources');
    }

    #[Route('/admin/resources/{resource_id}/delete', name: 'admin_resource_delete', requirements: ['resource_id' => '\\d+'], methods: ['POST'])]
    public function deleteResource(): Response
    {
        $this->requireCsrf(); $this->requirePermission('COURSE.EDIT'); $id = max(1, (int) $this->param('resource_id'));
        return $this->handle(function () use ($id): void { $this->resources->delete($id); $this->flash('success', 'The unused Resource was deleted.'); $this->redirect('/admin/resources'); }, '/admin/resources');
    }

    /** @param array<string,mixed> $item */
    private function itemForm(array $item, int $courseId, string $action): Response
    {
        return $this->render('admin-course-item-form', ['title' => ((int) ($item['id'] ?? 0) > 0 ? 'Edit ' : 'Create ') . 'Course Item', 'item' => $item, 'course_id' => $courseId, 'form_action' => $action, 'item_types' => CourseItemService::TYPES, 'resources' => $this->resources->library(), 'editor_questions' => (array) ($item['questions'] ?? []), 'load_ckeditor' => true, 'load_question_editor' => in_array((string) $item['item_type'], ['assessment','diagnostic'], true)]);
    }

    /** @return array<string,mixed> */
    private function blankItem(string $type): array
    {
        if (!in_array($type, CourseItemService::TYPES, true)) { $type = 'html_lesson'; }
        return ['id' => 0, 'item_key' => '', 'item_type' => $type, 'title' => '', 'description_html' => '', 'content_source' => '', 'type_config' => [], 'resource_id' => null, 'questions' => [], 'pass_mark' => 50, 'practice' => false, 'practice_enabled' => false, 'practice_pool_mode' => 'both', 'practice_question_count' => 5, 'graded_question_count' => 1, 'maximum_attempts' => null, 'time_limit_seconds' => 1800, 'score_policy' => 'highest', 'randomise_questions' => false, 'randomise_options' => false, 'negative_marking' => false, 'usage' => []];
    }

    /** @return array<string,mixed> */
    private function requireManagedItem(int $id): array
    {
        $user = $this->requireUser(); $item = $this->items->item($id);
        if ($user->hasPermission('PLATFORM.DASHBOARD.VIEW') || (int) $item['created_by_user_id'] === $user->id) { return $item; }
        foreach ($item['course_usage'] as $course) { if ($this->courses->userCanManageCourse((int) $course['id'], $user->id)) { return $item; } }
        throw new AccessDeniedHttpException('You do not have permission to edit this shared Course Item.');
    }

    /** @return array<string,mixed> */
    private function requireManagedCourse(int $courseId): array
    {
        $user = $this->requireUser(); $course = $this->courses->adminCourse($courseId);
        if (!$user->hasPermission('PLATFORM.DASHBOARD.VIEW') && !$this->courses->userCanManageCourse($courseId, $user->id)) { throw new AccessDeniedHttpException('You do not have permission to manage this course.'); }
        return $course;
    }

    private function courseId(): int { $id = (int) $this->param('id'); if ($id < 1) { throw new InvalidArgumentException('Invalid course identifier.'); } return $id; }
    private function itemId(): int { $id = (int) $this->param('item_id'); if ($id < 1) { throw new InvalidArgumentException('Invalid Course Item identifier.'); } return $id; }
    private function nodeId(): int { $id = (int) $this->param('node_id'); if ($id < 1) { throw new InvalidArgumentException('Invalid Course Content row.'); } return $id; }
}
