<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Auth\AuthService;
use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\CourseNavigation;
use CattoLearning\Course\GradeScale;
use CattoLearning\Course\AssessmentService;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Support\Slug;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/** Core-owned public Course Item previews and immutable Resource delivery. */
final class CoursePresentationController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly CourseRepository $courses, private readonly CourseItemService $items, private readonly CourseItemRenderer $renderer, private readonly ResourceLibraryService $resources, private readonly AssessmentService $assessments, private readonly AnalyticsEventRecorder $analytics)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/courses/{slug}/preview', name: 'course_public_preview', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function outline(): Response
    {
        $course = $this->publicCourse();
        $course['structure'] = $this->items->availability((int) $course['id'], null, true);
        $this->publicView($course, 'course_page');
        return $this->render('course-public-preview', ['title' => (string) $course['title'] . ' preview', 'course' => $course, 'course_content_mode' => true, 'is_public_preview' => true]);
    }

    #[Route('/courses/{slug}/preview/{node_id}/content', name: 'course_public_preview_content', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+'], methods: ['GET'])]
    public function item(): Response
    {
        $course = $this->publicCourse(); $nodeId = (int) $this->param('node_id'); $node = null;
        $course['structure'] = $this->items->availability((int) $course['id'], null, true);
        // The public structure holds only the sections and Course Items marked for public preview. A
        // public section opens here when it has a page of its own (an introduction or an outline).
        foreach (CourseNavigation::sequence($course['structure']) as $candidate) { if ((int) $candidate['id'] === $nodeId) { $node = $candidate; break; } }
        if ($node === null || !(bool) ($node['public_preview'] ?? false)) { throw $this->notFound('The public Course Item preview was not found.'); }
        $isSection = ($node['node_type'] ?? '') === 'section';
        $around = CourseNavigation::neighbours($course['structure'], $nodeId);
        $node['previous_node'] = $around['previous'];
        $node['next_node'] = $around['next'];
        if (in_array((string) ($node['item_type'] ?? ''), ['assessment', 'diagnostic'], true)) {
            throw $this->notFound('Assessments open from their direct assessment route.');
        }
        $node['rendered_html'] = GradeScale::resolve($isSection ? (string) $node['section_introduction_html'] : $this->renderer->render($node, (string) $course['slug'], $nodeId, true), $this->courses->gradeBands((int) $course['id']));
        if ($course['public_preview_query'] !== '') { $node['rendered_html'] = preg_replace('/(href="\/courses\/[^"?]+)(")/', '$1?preview=1$2', $node['rendered_html']) ?? $node['rendered_html']; }
        $course['current_node_id'] = $nodeId;
        $this->publicView($course, 'item', $nodeId, $isSection ? null : (int) $node['course_item_id']);
        return $this->render('course-public-preview-item', ['title' => (string) ($isSection ? $node['section_title'] : ($node['display_title_override'] ?: $node['item_title'])), 'course' => $course, 'item' => $node, 'course_content_mode' => true, 'is_public_preview' => true]);
    }

    #[Route('/courses/{slug}/preview/{node_id}/assessment/{key}', name: 'public_course_assessment', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+', 'key' => '[a-zA-Z0-9._-]+'], methods: ['GET','POST'])]
    public function assessment(): Response
    {
        $request = $this->requests->getCurrentRequest();
        $submitted = $request?->isMethod('POST') ?? false;
        if ($submitted) { $this->requireCsrf(); }
        $course = $this->publicCourse();
        $data = $this->assessments->publicPreview($this->param('slug'), (int) $this->param('node_id'), $this->param('key'), $submitted ? (array) ($_POST['answers'] ?? []) : null, !empty($course['public_preview_query']));
        $data['course']['public_preview_query'] = $course['public_preview_query'];
        $nodeId = (int) $this->param('node_id');
        $around = CourseNavigation::neighbours((array) $data['course']['structure'], $nodeId);
        $data['course']['current_node_id'] = $nodeId;
        $data['assessment']['previous_node'] = $around['previous'];
        $data['assessment']['next_node'] = $around['next'];
        return $this->render('assessment-public-preview', $data + ['title' => $data['assessment']['title'], 'is_public_preview' => true, 'node_id' => (int) $this->param('node_id')]);
    }

    #[Route('/course-resources/{public_id}', name: 'course_resource_file', requirements: ['public_id' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function resource(): Response
    {
        $resource = $this->resources->resourceByPublicId($this->param('public_id'));
        $user = $this->currentUser();
        if (!$this->items->canAccessResource((int) $resource['id'], $user?->id, $user?->hasPermission('PLATFORM.DASHBOARD.VIEW') ?? false)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You do not have access to this Resource.');
        }
        $response = new BinaryFileResponse((string) $resource['path']);
        $response->headers->set('Content-Type', (string) $resource['mime_type']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, (string) $resource['original_filename']);
        $response->setPrivate();
        return $response;
    }

    /**
     * A public preview page view by a visitor. ADMIN's ?preview=1 view of a draft is authoring and
     * is not counted.
     *
     * @param array<string,mixed> $course
     */
    private function publicView(array $course, string $context, ?int $nodeId = null, ?int $courseItemId = null): void
    {
        if ((string) $course['public_preview_query'] !== '') { return; }
        $this->analytics->courseViewed((int) $course['id'], AnalyticsSource::PublicPreview, $this->currentUser()?->id, $this->analyticsVisitor(), $context, $nodeId, $courseItemId);
    }

    /** @return array<string,mixed> */
    private function publicCourse(): array
    {
        $preview = (string) ($_GET['preview'] ?? '') === '1';
        $user = $preview ? $this->requirePermission('COURSE.PREVIEW') : null;
        $course = $this->courses->findBySlug(Slug::validate($this->param('slug')), !$preview);
        if ($course === null) { throw $this->notFound('The course was not found.'); }
        if ($user !== null && !$user->hasPermission('PLATFORM.DASHBOARD.VIEW') && !$this->courses->userCanManageCourse((int) $course['id'], $user->id)) { throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('You cannot preview this draft course.'); }
        $course['public_preview_query'] = $preview ? '?preview=1' : '';
        return $course;
    }
}
