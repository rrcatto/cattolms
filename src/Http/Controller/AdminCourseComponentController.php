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
        // Started from a Resource: offer only the item types that can use that file.
        $resource = null; $types = CourseItemService::TYPES;
        if (isset($_GET['resource_id'])) {
            $resource = $this->resources->resource(max(0, (int) $_GET['resource_id']));
            if ($resource === null) { throw $this->notFound('The Resource does not exist.'); }
            $types = CourseItemService::itemTypesForResource((string) $resource['resource_type']);
        }
        // "+ Add here" passes the exact place in the course through the type choice to the item form.
        $placement = $courseId > 0 ? ['parent_node_id' => max(0, (int) ($_GET['parent_node_id'] ?? 0)) ?: '', 'insert_index' => ctype_digit((string) ($_GET['insert_index'] ?? '')) ? (string) $_GET['insert_index'] : ''] : ['parent_node_id' => '', 'insert_index' => ''];
        $type = (string) ($_GET['type'] ?? '');
        if (!in_array($type, $types, true)) {
            if (count($types) === 1) { $type = $types[0]; }
            else {
                return $this->render('admin-course-item-type', ['title' => $resource === null ? 'Create new Course Item' : 'Create a Course Item from ' . $resource['title'], 'course_id' => $courseId, 'resource' => $resource, 'type_choices' => $this->typeChoices($types), 'placement' => $placement]);
            }
        }
        $item = $this->blankItem($type) + $placement;
        if ($resource !== null) { $item['resource_id'] = (int) $resource['id']; $item['title'] = (string) $resource['title']; }
        return $this->itemForm($item, $courseId, '/admin/course-items');
    }

    #[Route('/admin/course-items', name: 'admin_course_item_create', methods: ['POST'])]
    public function create(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = max(0, (int) ($_POST['course_id'] ?? 0));
        if ($courseId > 0) { $this->requireManagedCourse($courseId); }
        if (isset($_POST['question_action'])) { return $this->itemForm($this->items->editorDraft($_POST, $this->blankItem((string) ($_POST['item_type'] ?? 'assessment'))), $courseId, '/admin/course-items'); }
        if (($_POST['resource_action'] ?? '') === 'upload') { return $this->uploadIntoForm($this->blankItem((string) ($_POST['item_type'] ?? 'downloadable_file')), $courseId, '/admin/course-items', $user->id); }
        return $this->handle(function () use ($user, $courseId): void {
            $input = $this->withUploadedResource($_POST, $user->id);
            $id = $courseId > 0 ? $this->items->createAttached($courseId, $input, $user->id) : $this->items->create($input, $user->id);
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
        if (($_POST['resource_action'] ?? '') === 'upload') { return $this->uploadIntoForm($this->items->item($id), 0, '/admin/course-items/' . $id, $user->id); }
        return $this->handle(function () use ($user, $id): void {
            if (($_POST['item_action'] ?? '') === 'save_as') {
                $input = array_replace($_POST, ['item_key' => (string) ($_POST['copy_key'] ?? ''), 'title' => (string) ($_POST['copy_title'] ?? '')]);
                $copy = $this->items->saveAs($id, $input, $user->id);
                $this->flash('success', 'An independent Course Item was created.');
                $this->redirect('/admin/course-items/' . $copy);
            }
            $this->items->update($id, $this->withUploadedResource($_POST, $user->id), $user->id);
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
        // "+ Add here" names the exact parent and position; the Add item panels carry them through.
        $insert = ['parent' => max(0, (int) ($_GET['insert_parent'] ?? 0)), 'index' => isset($_GET['insert_index']) && ctype_digit((string) $_GET['insert_index']) ? (int) $_GET['insert_index'] : null];
        return $this->render('admin-course-content', ['title' => 'Course Content', 'course' => $this->items->courseContent($courseId), 'library_items' => array_values($libraryItems), 'item_search' => $search, 'type_choices' => $this->typeChoices(CourseItemService::TYPES), 'insert' => $insert]);
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
        $selected = $this->postedNodeIds();
        if (($_POST['direction'] ?? '') === 'into') {
            if ($selected === []) { $this->flash('danger', 'Select one or more Course Content rows.'); $this->redirect('/admin/courses/' . $courseId . '/content'); }
            $this->redirect('/admin/courses/' . $courseId . '/content/move-into?' . http_build_query(['node_ids' => $selected]));
        }
        return $this->arranged($courseId, fn() => $this->items->move($courseId, $selected, (string) ($_POST['direction'] ?? ''), $user->id), 'The selected rows were moved.');
    }

    #[Route('/admin/courses/{id}/content/{node_id}/move', name: 'admin_course_content_move', requirements: ['id' => '\\d+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function move(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId); $nodeId = $this->nodeId();
        return $this->arranged($courseId, fn() => $this->items->move($courseId, [$nodeId], (string) ($_POST['direction'] ?? ''), $user->id), 'The row was moved.');
    }

    /** Drag and drop, and the Move into page: rows go under a parent (empty for the outer level) at a position (empty for last). */
    #[Route('/admin/courses/{id}/content/arrange', name: 'admin_course_content_arrange', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function arrange(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId);
        $parent = trim((string) ($_POST['parent_node_id'] ?? '')); $index = trim((string) ($_POST['index'] ?? ''));
        return $this->arranged($courseId, function () use ($courseId, $user, $parent, $index): void {
            if (($parent !== '' && !ctype_digit($parent)) || ($index !== '' && !ctype_digit($index))) { throw new InvalidArgumentException('Choose a valid position.'); }
            $this->items->arrange($courseId, $this->postedNodeIds(), $parent === '' ? null : (int) $parent, $index === '' ? null : (int) $index, $user->id);
        }, 'The row was moved.');
    }

    #[Route('/admin/courses/{id}/content/move-into', name: 'admin_course_content_move_into', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function moveInto(): Response
    {
        $this->requirePermission('COURSE.EDIT'); $courseId = $this->courseId(); $this->requireManagedCourse($courseId);
        $selected = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['node_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
        return $this->handle(function () use ($courseId, $selected): Response {
            $course = $this->items->courseContent($courseId);
            $destinations = $this->items->moveDestinations($courseId, $selected);
            $moving = array_values(array_filter($course['structure'], static fn(array $row): bool => in_array((int) $row['id'], $selected, true)));
            $choices = array_values(array_filter($course['structure'], static fn(array $row): bool => in_array((int) $row['id'], $destinations, true)));
            return $this->render('admin-course-content-move-into', ['title' => 'Move into', 'course' => $course, 'moving' => $moving, 'choices' => $choices]);
        }, '/admin/courses/' . $courseId . '/content');
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

    #[Route('/admin/resources/{resource_id}', name: 'admin_resource_edit', requirements: ['resource_id' => '\\d+'], methods: ['GET'])]
    public function editResource(): Response
    {
        $this->requirePermission('COURSE.EDIT');
        $resource = $this->resources->resource(max(0, (int) $this->param('resource_id')));
        if ($resource === null) { throw $this->notFound('The Resource does not exist.'); }
        return $this->render('admin-resource-edit', ['title' => 'Resource · ' . $resource['title'], 'resource' => $resource, 'size_label' => ResourceLibraryService::sizeLabel((int) $resource['byte_size']), 'usage' => $this->resources->usage((int) $resource['id']), 'resource_types' => ResourceLibraryService::TYPES]);
    }

    #[Route('/admin/resources/{resource_id}', name: 'admin_resource_update', requirements: ['resource_id' => '\\d+'], methods: ['POST'])]
    public function updateResource(): Response
    {
        $this->requireCsrf(); $this->requirePermission('COURSE.EDIT'); $id = max(1, (int) $this->param('resource_id'));
        return $this->handle(function () use ($id): void { $this->resources->update($id, $_POST); $this->flash('success', 'The Resource details were saved.'); $this->redirect('/admin/resources/' . $id); }, '/admin/resources/' . $id);
    }

    #[Route('/admin/resources/{resource_id}/delete', name: 'admin_resource_delete', requirements: ['resource_id' => '\\d+'], methods: ['POST'])]
    public function deleteResource(): Response
    {
        $this->requireCsrf(); $this->requirePermission('COURSE.EDIT'); $id = max(1, (int) $this->param('resource_id'));
        return $this->handle(function () use ($id): void { $this->resources->delete($id); $this->flash('success', 'The unused Resource was deleted.'); $this->redirect('/admin/resources'); }, '/admin/resources');
    }

    /**
     * A Downloadable File may arrive with a new file instead of a chosen Resource. The upload goes
     * into the Resource Library once, classified from its name, and the item then references it.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withUploadedResource(array $input, int $userId): array
    {
        $upload = (array) ($_FILES['resource_upload'] ?? []);
        $type = (string) ($input['item_type'] ?? '');
        if (!array_key_exists($type, CourseItemService::RESOURCE_COMPATIBILITY) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $input;
        }
        $filename = basename((string) ($upload['name'] ?? ''));
        $title = trim((string) ($input['resource_upload_title'] ?? '')) ?: $filename;
        $input['resource_id'] = (string) $this->resources->upload($upload, ['title' => $title, 'resource_type' => CourseItemService::resourceTypeForUpload($type, $filename), 'original_filename' => $filename], $userId);
        return $input;
    }

    /**
     * "Upload and select": store the file in the Resource Library, select it, and show the same
     * form again with everything already entered. Nothing else is saved yet.
     *
     * @param array<string,mixed> $before
     */
    private function uploadIntoForm(array $before, int $courseId, string $action, int $userId): Response
    {
        if ($courseId > 0) { $this->requireManagedCourse($courseId); }
        $draft = $this->items->editorDraft($_POST, $before);
        try {
            if ((int) (($_FILES['resource_upload'] ?? [])['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \InvalidArgumentException('Choose a file to upload first.');
            }
            $draft['resource_id'] = (int) $this->withUploadedResource($_POST, $userId)['resource_id'];
            $file = $this->resources->resource((int) $draft['resource_id']);
            $notice = ['tone' => 'success', 'text' => 'Uploaded ' . ($file['original_filename'] ?? 'the file') . ' to the Resource Library and selected it. Save the item to keep this choice.'];
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $notice = ['tone' => 'danger', 'text' => $exception->getMessage()];
        }
        return $this->itemForm($draft, $courseId, $action, $notice);
    }

    /**
     * @param list<string> $types
     * @return list<array{value:string,label:string,description:string}>
     */
    private function typeChoices(array $types): array
    {
        return array_map(static fn(string $type): array => ['value' => $type, 'label' => CourseItemService::TYPE_LABELS[$type], 'description' => CourseItemService::TYPE_DESCRIPTIONS[$type]], $types);
    }

    /**
     * @param array<string,mixed> $item
     * @param array{tone:string,text:string}|null $notice
     */
    private function itemForm(array $item, int $courseId, string $action, ?array $notice = null): Response
    {
        $type = (string) $item['item_type'];
        // null means any file (Downloadable File); a type absent from the map uses no Resource.
        $accepted = array_key_exists($type, CourseItemService::RESOURCE_COMPATIBILITY) ? CourseItemService::RESOURCE_COMPATIBILITY[$type] : [];
        $resources = array_values(array_filter($this->resources->library(), static fn(array $resource): bool => $accepted === null || in_array((string) $resource['resource_type'], $accepted, true)));
        $course = $courseId > 0 ? $this->items->courseContent($courseId) : null;
        $creating = (int) ($item['id'] ?? 0) === 0;
        return $this->render('admin-course-item-form', ['title' => $creating ? ($course !== null ? 'Create and add to ' . $course['title'] : 'Create ' . CourseItemService::TYPE_LABELS[$type] . ' item') : 'Edit Course Item', 'item' => $item, 'course_id' => $courseId, 'course_structure' => $course['structure'] ?? [], 'form_action' => $action, 'item_types' => CourseItemService::TYPES, 'type_label' => CourseItemService::TYPE_LABELS[$type], 'resources' => $resources, 'all_resources' => $this->resources->library(), 'resource_notice' => $notice, 'editor_questions' => (array) ($item['questions'] ?? []), 'load_ckeditor' => true, 'load_question_editor' => in_array((string) $item['item_type'], ['assessment','diagnostic'], true)]);
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

    /** @return list<int> */
    private function postedNodeIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['node_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
    }

    /**
     * Runs one Course Content move. The drag-and-drop tree asks with HX-Request and gets the saved
     * tree back to swap in, or the authoritative tree and a 422 with the reason when the move was
     * refused. A plain form post redirects to the page with a message, as every other form does.
     */
    private function arranged(int $courseId, callable $move, string $success): Response
    {
        if (!$this->isHtmxRequest()) {
            return $this->handle(function () use ($move, $success): void { $move(); $this->flash('success', $success); }, '/admin/courses/' . $courseId . '/content');
        }
        $error = '';
        try { $move(); }
        catch (InvalidArgumentException|\RuntimeException $exception) { $error = $exception->getMessage(); }
        return $this->renderFragment('partials/admin/course-content-tree', ['course' => $this->items->courseContent($courseId), 'tree_error' => $error], $error === '' ? 200 : 422);
    }
}
