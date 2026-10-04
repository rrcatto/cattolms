<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Course\CourseReviewRepository;
use CattoLearning\Course\CourseReviewService;
use CattoLearning\Support\Pagination;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Moderation of learner course reviews. Nothing a learner writes is published until it is
 * approved here; rejecting changes a review's status and never deletes it.
 */
final class AdminCourseReviewController extends BaseController
{
    private const BASE = '/admin/course-reviews';
    private const STATUSES = ['pending', 'approved', 'rejected'];

    public function __construct(
        AuthService $auth,
        ThemeRenderer $view,
        RequestStack $requests,
        private readonly CourseReviewService $reviews,
        private readonly CourseReviewRepository $queue
    ) {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/course-reviews', name: 'admin_course_reviews', methods: ['GET'])]
    public function index(): Response
    {
        $this->requirePermission('COURSE.REVIEW.MANAGE');
        // Pending is the default view: the queue is what this page is for.
        $status = (string) ($_GET['status'] ?? 'pending');
        $status = in_array($status, [...self::STATUSES, 'all'], true) ? $status : 'pending';
        $course = ctype_digit((string) ($_GET['course_id'] ?? '')) && (int) $_GET['course_id'] > 0 ? (int) $_GET['course_id'] : null;
        $rating = preg_match('/^[1-5]$/', (string) ($_GET['rating'] ?? '')) === 1 ? (int) $_GET['rating'] : null;
        $filters = array_filter(['status' => $status === 'all' ? null : $status, 'course_id' => $course, 'rating' => $rating], static fn(mixed $value): bool => $value !== null);
        $pagination = Pagination::create($this->request()->query->get('reviews_page'), $this->request()->query->get('reviews_page_size'), $this->queue->moderationCount($filters), 25);
        $query = ['status' => $status, 'course_id' => $course ?? '', 'rating' => $rating ?? ''];
        $return = self::BASE . '?' . http_build_query(array_filter($query + ['reviews_page' => $pagination->page], static fn(mixed $value): bool => $value !== ''));
        return $this->render('admin-course-reviews', [
            'title' => 'Course Reviews',
            'reviews' => $this->queue->moderationList($filters, $pagination->pageSize, $pagination->offset),
            'counts' => $this->queue->statusCounts(),
            'courses' => $this->queue->reviewedCourses(),
            'filter' => ['status' => $status, 'course_id' => $course === null ? '' : (string) $course, 'rating' => $rating === null ? '' : (string) $rating],
            'reviews_pagination' => PlatformAdministrationService::paginationPayload('reviews', $pagination, self::BASE, 'Course reviews', $query),
            'return_to' => $return,
            'max_note' => CourseReviewService::MAX_NOTE,
        ]);
    }

    #[Route('/admin/course-reviews/{id}/moderate', name: 'admin_course_review_moderate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function moderate(): Response
    {
        $this->requireCsrf();
        $moderator = $this->requirePermission('COURSE.REVIEW.MANAGE');
        $return = (string) ($_POST['return'] ?? '');
        $return = str_starts_with($return, self::BASE) ? $return : self::BASE;
        $id = (int) $this->param('id');
        return $this->handle(function () use ($moderator, $id): void {
            $decision = (string) ($_POST['decision'] ?? '');
            $changed = match ($decision) {
                'approve' => $this->reviews->approve($id, $moderator->id, $_POST['revision'] ?? null, $_POST['note'] ?? ''),
                'reject' => $this->reviews->reject($id, $moderator->id, $_POST['revision'] ?? null, $_POST['note'] ?? '', ($_POST['withdraw'] ?? '') === '1'),
                // A rejected edit whose earlier approved version is still published: take that down too.
                'withdraw' => $this->reviews->reject($id, $moderator->id, $_POST['revision'] ?? null, $_POST['note'] ?? '', true),
                default => throw new \InvalidArgumentException('Choose to approve or reject the review.'),
            };
            $this->flash('success', !$changed
                ? 'The review already had that decision, so nothing changed.'
                : match ($decision) { 'approve' => 'The review is published.', 'withdraw' => 'The review is no longer published.', default => 'The review was rejected.' });
        }, $return);
    }
}
