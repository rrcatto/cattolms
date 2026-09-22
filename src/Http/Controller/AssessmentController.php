<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Auth\AuthService;
use CattoLearning\Course\AssessmentService;
use CattoLearning\Course\LearningService;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AssessmentController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly AssessmentService $assessments, private readonly LearningService $learning)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/learn/{slug}/item/{node_id}/assessment/{key}', name: 'assessment_course_item_overview', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+', 'key' => '[a-zA-Z0-9._-]+'], methods: ['GET'])]
    public function overview(): Response
    {
        $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $node = (int) $this->param('node_id'); $key = $this->param('key'); $preview = $this->previewMode();
        return $this->handle(function () use ($user, $slug, $node, $key, $preview): Response { $data = $this->assessments->overview($user->id, $slug, $node, $key, $preview); $data['course'] = $this->learning->courseHome($user->id, $slug, $preview); $data['course']['current_node_id'] = $node; $data['assessment'] = $this->withCourseNavigation((array) $data['assessment'], (array) $data['course'], $node); return $this->render('assessment-overview', $data + ['title' => (string) $data['assessment']['title'], 'start_action' => '/learn/' . rawurlencode($slug) . '/item/' . $node . '/assessment/' . rawurlencode($key) . '/start' . $this->previewQuery($preview), 'preview_query' => $this->previewQuery($preview)]); }, '/learn/' . rawurlencode($slug));
    }

    #[Route('/learn/{slug}/item/{node_id}/assessment/{key}/start', name: 'assessment_course_item_start', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'node_id' => '\\d+', 'key' => '[a-zA-Z0-9._-]+'], methods: ['POST'])]
    public function start(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $node = (int) $this->param('node_id'); $key = $this->param('key'); $preview = $this->previewMode(); $mode = (string) ($_POST['mode'] ?? 'graded');
        return $this->handle(function () use ($user, $slug, $node, $key, $mode, $preview): void { $session = $this->assessments->startAttempt($user->id, $slug, $node, $key, $mode, $preview); $this->redirect('/learn/' . rawurlencode($slug) . '/assessment/session/' . rawurlencode((string) $session['public_id']) . $this->previewQuery($preview)); }, '/learn/' . rawurlencode($slug) . '/item/' . $node . '/assessment/' . rawurlencode($key) . $this->previewQuery($preview));
    }

    #[Route('/learn/{slug}/assessment/session/{session}', name: 'assessment_session', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function session(): Response
    {
        $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $publicId = $this->param('session'); $preview = $this->previewMode();
        return $this->handle(function () use ($user, $slug, $publicId, $preview): Response { $session = $this->assessments->session($user->id, $publicId); if ($session['status'] !== 'in_progress') { $this->redirect($this->sessionUrl($slug, $publicId, '/result', $preview)); } $feedback = is_array($_SESSION['assessment_feedback'] ?? null) ? $_SESSION['assessment_feedback'] : null; unset($_SESSION['assessment_feedback']); return $this->render('assessment-question', ['course' => $this->learning->courseHome($user->id, $slug, $preview), 'title' => (string) $session['assessment_title'], 'session' => $session, 'question' => $session['question'], 'feedback' => $feedback, 'respond_action' => $this->sessionUrl($slug, $publicId, '/respond', $preview), 'finish_action' => $this->sessionUrl($slug, $publicId, '/finish', $preview), 'is_preview' => $preview, 'preview_query' => $this->previewQuery($preview), 'load_assessment_timer' => true]); }, '/learn/' . rawurlencode($slug) . $this->previewQuery($preview));
    }

    #[Route('/learn/{slug}/assessment/session/{session}/respond', name: 'assessment_respond', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function respond(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $publicId = $this->param('session'); $preview = $this->previewMode();
        return $this->handle(function () use ($user, $slug, $publicId, $preview): void { $next = $this->assessments->respond($user->id, $publicId, (int) ($_POST['question_id'] ?? 0), isset($_POST['option_id']) ? (int) $_POST['option_id'] : null, isset($_POST['skip'])); if (isset($next['feedback'])) { $_SESSION['assessment_feedback'] = $next['feedback']; } $this->redirect($this->sessionUrl($slug, $publicId, $next['status'] === 'in_progress' ? '' : '/result', $preview)); }, $this->sessionUrl($slug, $publicId, '', $preview));
    }

    #[Route('/learn/{slug}/assessment/session/{session}/finish', name: 'assessment_finish', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    public function finish(): Response
    {
        $this->requireCsrf(); $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $publicId = $this->param('session'); $preview = $this->previewMode();
        return $this->handle(function () use ($user, $slug, $publicId, $preview): void { $this->assessments->finish($user->id, $publicId); $this->redirect($this->sessionUrl($slug, $publicId, '/result', $preview)); }, $this->sessionUrl($slug, $publicId, '', $preview));
    }

    #[Route('/learn/{slug}/assessment/session/{session}/result', name: 'assessment_result', requirements: ['slug' => '[a-zA-Z0-9_-]+', 'session' => '[0-9a-fA-F-]{36}'], methods: ['GET'])]
    public function result(): Response
    {
        $user = $this->requirePermission('LEARNING.ASSESSMENT.TAKE'); $slug = $this->param('slug'); $publicId = $this->param('session'); $preview = $this->previewMode();
        return $this->handle(function () use ($user, $slug, $publicId, $preview): Response { $session = $this->assessments->result($user->id, $publicId); return $this->render('assessment-session-result', ['course' => $this->learning->courseHome($user->id, $slug, $preview), 'preview_query' => $this->previewQuery($preview), 'title' => 'Assessment result', 'session' => $session, 'course_url' => '/learn/' . rawurlencode($slug) . $this->previewQuery($preview), 'retake_url' => '/learn/' . rawurlencode($slug) . '/item/' . (int) $session['structure_node_id'] . '/assessment/' . rawurlencode((string) $session['assessment_key']) . $this->previewQuery($preview), 'is_preview' => $preview]); }, '/learn/' . rawurlencode($slug) . $this->previewQuery($preview));
    }

    private function previewMode(): bool { $preview = (string) ($_GET['preview'] ?? '') === '1'; if ($preview) { $this->requireCourseAuthor(); } return $preview; }
    private function previewQuery(bool $preview): string { return $preview ? '?preview=1' : ''; }
    private function sessionUrl(string $slug, string $session, string $suffix, bool $preview): string { return '/learn/' . rawurlencode($slug) . '/assessment/session/' . rawurlencode($session) . $suffix . $this->previewQuery($preview); }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    private function withCourseNavigation(array $item, array $course, int $nodeId): array
    {
        $sequence = array_values(array_filter((array) ($course['structure'] ?? []), static fn(array $node): bool => ($node['node_type'] ?? '') === 'item'));
        $index = array_search($nodeId, array_map(static fn(array $node): int => (int) $node['id'], $sequence), true);
        $item['previous_node'] = $index !== false && $index > 0 ? $sequence[$index - 1] : null;
        $item['next_node'] = $index !== false && isset($sequence[$index + 1]) ? $sequence[$index + 1] : null;
        return $item;
    }
}
