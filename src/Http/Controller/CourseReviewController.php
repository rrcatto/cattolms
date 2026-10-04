<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Auth\AuthService;
use CattoLearning\Course\CourseReviewService;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A learner's rating and review of a course they have studied. One page: the form, filled with the
 * learner's review when they have one, and where that review stands in moderation. Eligibility is
 * decided by {@see CourseReviewService}, never by whether a link was shown.
 */
final class CourseReviewController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly CourseReviewService $reviews)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/learn/{slug}/review', name: 'course_review', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function show(): Response
    {
        $user = $this->requirePermission('LEARNING.REVIEW.CREATE');
        $course = $this->course();
        $eligible = $this->reviews->canReview($user->id, (int) $course['id']);
        return $this->render('course-review', [
            'title' => 'Review ' . (string) $course['title'],
            'course' => $course,
            'eligible' => $eligible,
            'review' => $eligible ? $this->reviews->learnerReview((int) $course['id'], $user->id) : null,
            'max_comment' => CourseReviewService::MAX_COMMENT,
        ], $eligible ? 200 : 403);
    }

    #[Route('/learn/{slug}/review', name: 'course_review_save', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['POST'])]
    public function save(): Response
    {
        $this->requireCsrf();
        $user = $this->requirePermission('LEARNING.REVIEW.CREATE');
        $course = $this->course();
        $back = '/learn/' . rawurlencode((string) $course['slug']) . '/review';
        return $this->handle(function () use ($user, $course): void {
            $outcome = $this->reviews->submit($user->id, (int) $course['id'], $_POST['rating'] ?? null, $_POST['comment'] ?? '');
            $this->flash('success', match ($outcome) {
                'submitted' => 'Thank you. Your review will be published once it has been checked.',
                'updated' => 'Your changes will be published once they have been checked.',
                default => 'Your review is unchanged.',
            });
        }, $back);
    }

    /** @return array<string,mixed> */
    private function course(): array
    {
        return $this->reviews->course((string) $this->param('slug')) ?? throw new NotFoundHttpException('The course could not be found.');
    }
}
