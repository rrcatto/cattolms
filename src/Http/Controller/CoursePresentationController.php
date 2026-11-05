<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Auth\AuthService;
use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Course\Catwalk\Narrations;
use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\CourseNavigation;
use CattoLearning\Course\GradeScale;
use CattoLearning\Course\CourseItemService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Course\PublicAssessmentAttempts;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Support\Slug;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Core-owned public Course Item previews and immutable Resource delivery. The public preview gives a visitor the
 * learner's own experience (owner, 2026-11-01: it "is supposed to SHOWCASE to potential students the exact same
 * experience they would get taking the course"): Mark complete with its ticks and progress, and assessments with their
 * start page, one question at a time and the result. A public preview records nothing, so the visitor's ticks and
 * attempts are kept in their own session only.
 */
final class CoursePresentationController extends BaseController
{
    /** The visitor's ticks, by course and placement. */
    private const TICKS = 'public_preview_done';
    /** The visitor's assessment attempts, by public id; only the latest are kept. */
    private const ATTEMPTS = 'public_assessment_attempts';
    private const ATTEMPTS_KEPT = 20;
    private const FEEDBACK = 'public_assessment_feedback';

    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly CourseRepository $courses, private readonly CourseItemService $items, private readonly CourseItemRenderer $renderer, private readonly ResourceLibraryService $resources, private readonly AnalyticsEventRecorder $analytics, private readonly Narrations $narrations, private readonly \CattoLearning\Course\Parts\CoursePartPdfs $partPdfs, private readonly PublicAssessmentAttempts $attempts)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/courses/{slug}/preview', name: 'course_public_preview', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function outline(): Response
    {
        $course = $this->publicCourse();
        $course = $this->withVisitorTicks($course);
        $this->publicView($course, 'course_page');
        $course['introduction_html'] = GradeScale::resolve((string) ($course['introduction_html'] ?? ''), $this->courses->gradeBands((int) $course['id']));
        return $this->render('course-public-preview', ['title' => (string) $course['title'] . ' preview', 'course' => $course, 'course_content_mode' => true, 'is_public_preview' => true]);
    }

    #[Route('/courses/{slug}/preview/{node_id}/content', name: 'course_public_preview_content', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+'], methods: ['GET'])]
    public function item(): Response
    {
        $course = $this->withVisitorTicks($this->publicCourse()); $nodeId = (int) $this->param('node_id'); $node = null;
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
        $next = $around['next'];
        $continue = $next === null ? '/courses/' . rawurlencode((string) $course['slug']) . '/preview' : '/courses/' . rawurlencode((string) $course['slug']) . '/preview/' . (int) $next['id'] . (in_array((string) ($next['item_type'] ?? ''), ['assessment', 'diagnostic'], true) ? '/assessment/' . rawurlencode((string) $next['item_key']) : '/content');
        $node['rendered_html'] = GradeScale::resolve($isSection ? (string) $node['section_introduction_html'] : $this->renderer->render($node, (string) $course['slug'], $nodeId, true, [], ['continue' => $continue]), $this->courses->gradeBands((int) $course['id']));
        if ($course['public_preview_query'] !== '') { $node['rendered_html'] = preg_replace('/(href="\/courses\/[^"?]+)(")/', '$1?preview=1$2', $node['rendered_html']) ?? $node['rendered_html']; }
        $course['current_node_id'] = $nodeId;
        $this->publicView($course, 'item', $nodeId, $isSection ? null : (int) $node['course_item_id']);
        return $this->render('course-public-preview-item', ['title' => (string) ($isSection ? ($node['section_number'] !== null ? 'Module ' . $node['section_number'] . ': ' : '') . $node['section_title'] : ($node['display_title_override'] ?: $node['item_title'])), 'course' => $course, 'item' => $node, 'course_content_mode' => true, 'is_public_preview' => true, 'load_catwalk' => str_contains((string) $node['rendered_html'], 'class="cw-deck"'), 'load_flashcards' => str_contains((string) $node['rendered_html'], 'data-cl-flashcards')]);
    }

    /** A public part's PDF (owner, 2026-10-28, decision 6A): only what the public preview shows. */
    #[Route('/courses/{slug}/preview/{node_id}/pdf', name: 'course_public_preview_pdf', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+'], methods: ['GET'])]
    public function pdf(): Response
    {
        $course = $this->publicCourse(); $nodeId = (int) $this->param('node_id'); $node = null;
        $course['structure'] = $this->items->availability((int) $course['id'], null, true);
        foreach ($course['structure'] as $candidate) { if ((int) $candidate['id'] === $nodeId) { $node = $candidate; break; } }
        if ($node === null || !(bool) ($node['public_preview'] ?? false) || !\CattoLearning\Course\Parts\CoursePartPdfs::printable($node)) { throw $this->notFound('This part has no PDF.'); }
        $rendered = GradeScale::resolve($this->renderer->render($node, (string) $course['slug'], $nodeId, true), $this->courses->gradeBands((int) $course['id']));
        $heading = \CattoLearning\Course\Parts\CoursePartPdfs::heading($course, $node);
        $html = $this->view->renderFragment('partials/course-part-pdf', ['pdf' => $heading + ['body' => \CattoLearning\Course\Parts\CoursePartPdfs::body($node, $rendered)]]);
        return \CattoLearning\Http\FileDownload::attachment($this->partPdfs->file($html), \CattoLearning\Course\Parts\CoursePartPdfs::filename($heading['module'], $heading['part'] === 'Lesson' ? '' : $heading['part']), 'application/pdf');
    }

    /** The visitor's tick on a public part, a toggle as the learner's is (owner, 2026-11-01); kept in their session only. */
    #[Route('/courses/{slug}/preview/{node_id}/complete', name: 'course_public_preview_mark_part', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+'], methods: ['POST'])]
    public function markPart(): Response
    {
        $nodeId = (int) $this->param('node_id');
        // A plain form comes back to the buttons it was pressed at, not the top of the page.
        $back = '/courses/' . rawurlencode($this->param('slug')) . '/preview/' . $nodeId . '/content' . ((string) ($_GET['preview'] ?? '') === '1' ? '?preview=1' : '') . '#cl-course-item-paging';
        if (!$this->isHtmxRequest()) {
            $this->requireCsrf();
            $this->tickPart($nodeId);
            $this->redirect($back);
        }
        // With JavaScript the page does not reload or move (owner, 2026-11-02). An expired form, or an author's preview
        // signed out, reloads the page instead, which says why.
        if ((string) ($_GET['preview'] ?? '') === '1' && $this->currentUser() === null) { return $this->htmxReloadPage(); }
        try {
            $this->requireCsrf();
            $done = $this->tickPart($nodeId);
        } catch (HttpExceptionInterface) {
            return $this->htmxReloadPage();
        }
        $course = $this->withVisitorTicks($this->publicCourse());
        $course['current_node_id'] = $nodeId;
        return $this->renderFragment('partials/course-part-done', [
            'course' => $course, 'public_preview' => true, 'presentation_query' => '', 'is_preview' => false,
            'rows' => CourseNavigation::rowsUpTo((array) $course['structure'], $nodeId),
            'paging_complete' => ['action' => '/courses/' . rawurlencode((string) $course['slug']) . '/preview/' . $nodeId . '/complete' . $course['public_preview_query'], 'done' => $done],
        ]);
    }

    /** Keeps the visitor's tick on a part in their session, and says whether it is now ticked. */
    private function tickPart(int $nodeId): bool
    {
        $course = $this->withVisitorTicks($this->publicCourse()); $node = null;
        foreach ($course['structure'] as $candidate) { if ((int) $candidate['id'] === $nodeId) { $node = $candidate; break; } }
        if ($node === null || ($node['node_type'] ?? '') !== 'item' || in_array((string) ($node['item_type'] ?? ''), ['assessment', 'diagnostic'], true)) { throw $this->notFound('There is no part to mark complete here.'); }
        $completed = $this->posted('completed') === '1';
        $this->tick((int) $course['id'], $nodeId, $completed);
        return $completed;
    }

    /** An assessment's start page, the learner's own: practice or graded, or the readiness check. */
    #[Route('/courses/{slug}/preview/{node_id}/assessment/{key}', name: 'public_course_assessment', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+', 'key' => '[a-zA-Z0-9._-]+'], methods: ['GET'])]
    public function assessment(): Response
    {
        $course = $this->withVisitorTicks($this->publicCourse()); $nodeId = (int) $this->param('node_id'); $key = $this->param('key');
        try { $data = $this->attempts->overview($course, $nodeId, $key); } catch (\InvalidArgumentException) { throw $this->notFound('The public assessment was not found.'); }
        $course['current_node_id'] = $nodeId;
        $around = CourseNavigation::neighbours((array) $course['structure'], $nodeId);
        $data['assessment']['previous_node'] = $around['previous'];
        $data['assessment']['next_node'] = $around['next'];
        return $this->render('assessment-overview', $data + ['course' => $course, 'title' => (string) $data['assessment']['title'], 'start_action' => $this->assessmentPath($course, $nodeId, $key) . '/start' . $course['public_preview_query'], 'preview_query' => '', 'is_public_preview' => true]);
    }

    #[Route('/courses/{slug}/preview/{node_id}/assessment/{key}/start', name: 'public_course_assessment_start', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+', 'key' => '[a-zA-Z0-9._-]+'], methods: ['POST'])]
    public function startAssessment(): Response
    {
        $this->requireCsrf();
        $course = $this->publicCourse(); $nodeId = (int) $this->param('node_id'); $key = $this->param('key');
        return $this->handle(function () use ($course, $nodeId, $key): void {
            $attempt = $this->attempts->start($course, $nodeId, $key, (string) ($_POST['mode'] ?? 'graded'), array_values($this->storedAttempts()));
            $this->keepAttempt($attempt);
            $this->redirect($this->attemptPath($course, (string) $attempt['public_id']));
        }, $this->assessmentPath($course, $nodeId, $key) . $course['public_preview_query']);
    }

    /** One question at a time, with Skip, the timer and Finish now. */
    #[Route('/courses/{slug}/preview/assessment/{session}', name: 'public_course_assessment_session', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function assessmentSession(): Response
    {
        $course = $this->withVisitorTicks($this->publicCourse());
        $attempt = $this->keepAttempt($this->attempts->session($this->storedAttempt($course)));
        $feedback = is_array($_SESSION[self::FEEDBACK] ?? null) && ($_SESSION[self::FEEDBACK]['session'] ?? '') === (string) $attempt['public_id'] ? $_SESSION[self::FEEDBACK] : null; unset($_SESSION[self::FEEDBACK]);
        $course['current_node_id'] = (int) $attempt['node_id'];
        if ($attempt['status'] !== 'in_progress') {
            if ($feedback === null) { $this->redirect($this->attemptPath($course, (string) $attempt['public_id'], '/result')); }
            return $this->render('assessment-last-answer', ['course' => $course, 'title' => (string) $attempt['assessment_title'], 'session' => $this->attempts->result($attempt, (array) $course['structure']), 'feedback' => $feedback, 'result_url' => $this->attemptPath($course, (string) $attempt['public_id'], '/result'), 'is_preview' => false, 'is_public_preview' => true, 'preview_query' => '']);
        }
        $session = $this->attempts->view($attempt);
        return $this->render('assessment-question', ['course' => $course, 'title' => (string) $session['assessment_title'], 'session' => $session, 'question' => $session['question'], 'feedback' => $feedback, 'respond_action' => $this->attemptPath($course, (string) $attempt['public_id'], '/respond'), 'finish_action' => $this->attemptPath($course, (string) $attempt['public_id'], '/finish'), 'is_preview' => false, 'is_public_preview' => true, 'preview_query' => '', 'load_assessment_timer' => true]);
    }

    #[Route('/courses/{slug}/preview/assessment/{session}/respond', name: 'public_course_assessment_respond', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function respondToAssessment(): Response
    {
        $this->requireCsrf();
        $course = $this->publicCourse(); $attempt = $this->storedAttempt($course);
        return $this->handle(function () use ($course, $attempt): void {
            $answer = $this->attempts->respond($attempt, (int) ($_POST['question_id'] ?? 0), isset($_POST['option_id']) ? (int) $_POST['option_id'] : null, isset($_POST['skip']));
            $attempt = $this->keepAttempt($answer['attempt']);
            if ($answer['feedback'] !== null) { $_SESSION[self::FEEDBACK] = ['session' => (string) $attempt['public_id']] + $answer['feedback']; }
            $this->redirect($this->attemptPath($course, (string) $attempt['public_id'], $attempt['status'] === 'in_progress' || $answer['feedback'] !== null ? '' : '/result'));
        }, $this->attemptPath($course, (string) $attempt['public_id']));
    }

    #[Route('/courses/{slug}/preview/assessment/{session}/finish', name: 'public_course_assessment_finish', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function finishAssessment(): Response
    {
        $this->requireCsrf();
        $course = $this->publicCourse(); $attempt = $this->keepAttempt($this->attempts->finish($this->storedAttempt($course)));
        $this->redirect($this->attemptPath($course, (string) $attempt['public_id'], '/result'));
    }

    /** The result and its question report, as the learner's. */
    #[Route('/courses/{slug}/preview/assessment/{session}/result', name: 'public_course_assessment_result', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function assessmentResult(): Response
    {
        $publicCourse = $this->publicCourse();
        $attempt = $this->keepAttempt($this->attempts->session($this->storedAttempt($publicCourse)));
        if ($attempt['status'] === 'in_progress') { $this->redirect($this->attemptPath($publicCourse, (string) $attempt['public_id'])); }
        $course = $this->withVisitorTicks($publicCourse);
        $course['current_node_id'] = (int) $attempt['node_id'];
        $query = $course['public_preview_query'];
        $session = $this->attempts->result($attempt, (array) $course['structure']);
        $session['next_node'] = CourseNavigation::neighbours((array) $course['structure'], (int) $attempt['node_id'])['next'];
        $session['course_result'] = $this->attempts->courseResult($attempt, array_values($this->storedAttempts()));
        return $this->render('assessment-session-result', ['course' => $course, 'title' => 'Assessment result', 'session' => $session, 'review_prompt' => null, 'is_preview' => false, 'is_public_preview' => true, 'preview_query' => '', 'item_base' => '/courses/' . rawurlencode((string) $course['slug']) . '/preview/', 'item_query' => $query, 'course_url' => '/courses/' . rawurlencode((string) $course['slug']) . '/preview' . $query, 'retake_url' => $this->assessmentPath($course, (int) $attempt['node_id'], (string) $attempt['assessment_key']) . $query]);
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
     * A slide's narration (CatWalk, owner 2026-10-28), heard by whoever may see its Presentation: anyone where it is
     * public, otherwise its course's authors and learners who have reached it. Anyone else is told it is not found.
     */
    #[Route('/narrations/{public_id}', name: 'course_slide_narration', requirements: ['public_id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function narration(): Response
    {
        $user = $this->currentUser();
        $clip = $this->narrations->playable($this->param('public_id'), $user?->id, $user?->hasPermission('PLATFORM.DASHBOARD.VIEW') ?? false);
        if ($clip === null) { throw $this->notFound('The narration was not found.'); }
        $response = new BinaryFileResponse($clip['path']);
        $response->headers->set('Content-Type', $clip['content_type']);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->setMaxAge(3600);
        return $response;
    }

    /**
     * The public outline with the visitor's ticks, a section ticked once every part in it is, and their progress over
     * the parts the preview shows.
     *
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    private function withVisitorTicks(array $course): array
    {
        $done = [];
        foreach ((array) ($_SESSION[self::TICKS][(int) $course['id']] ?? []) as $nodeId => $ticked) { if ($ticked === true) { $done[(int) $nodeId] = true; } }
        $course['structure'] = CourseNavigation::withDone($this->items->availability((int) $course['id'], null, true), $done);
        $progress = CourseNavigation::progress($course['structure'], $done);
        if ($progress['total'] > 0) { $course['progress'] = $progress; }
        return $course;
    }

    private function tick(int $courseId, int $nodeId, bool $done): void
    {
        if ($done) { $_SESSION[self::TICKS][$courseId][$nodeId] = true; } else { unset($_SESSION[self::TICKS][$courseId][$nodeId]); }
    }

    /** @return array<string,array<string,mixed>> */
    private function storedAttempts(): array
    {
        return is_array($_SESSION[self::ATTEMPTS] ?? null) ? $_SESSION[self::ATTEMPTS] : [];
    }

    /**
     * The visitor's attempt named in the address, on this course; anyone else's, or a forgotten one, is not found.
     *
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    private function storedAttempt(array $course): array
    {
        $attempt = $this->storedAttempts()[strtolower($this->param('session'))] ?? null;
        if (!is_array($attempt) || (int) $attempt['course_id'] !== (int) $course['id']) { throw $this->notFound('The assessment attempt was not found.'); }
        return $attempt;
    }

    /**
     * Keeps an attempt in the visitor's session (the latest ATTEMPTS_KEPT); a finished one that counts ticks its part,
     * as a learner's does.
     *
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>
     */
    private function keepAttempt(array $attempt): array
    {
        $attempts = $this->storedAttempts();
        $attempts[(string) $attempt['public_id']] = $attempt;
        uasort($attempts, static fn(array $a, array $b): int => (int) $a['started_at'] <=> (int) $b['started_at']);
        $_SESSION[self::ATTEMPTS] = array_slice($attempts, -self::ATTEMPTS_KEPT, null, true);
        if (PublicAssessmentAttempts::ticksItsPart($attempt)) { $this->tick((int) $attempt['course_id'], (int) $attempt['node_id'], true); }
        return $attempt;
    }

    /** @param array<string,mixed> $course */
    private function assessmentPath(array $course, int $nodeId, string $key): string
    {
        return '/courses/' . rawurlencode((string) $course['slug']) . '/preview/' . $nodeId . '/assessment/' . rawurlencode($key);
    }

    /** @param array<string,mixed> $course */
    private function attemptPath(array $course, string $publicId, string $suffix = ''): string
    {
        return '/courses/' . rawurlencode((string) $course['slug']) . '/preview/assessment/' . rawurlencode($publicId) . $suffix . $course['public_preview_query'];
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
