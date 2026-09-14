<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Course\LearningService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

use CattoLearning\Application\PlatformAdministrationService;

use CattoLearning\Course\CatalogueFilter;
use CattoLearning\Course\CourseService;

use CattoLearning\View\ThemeRenderer;

use CattoLearning\Auth\AuthService;
use CattoLearning\Support\Pagination;
use CattoLearning\Support\Slug;


use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Coordinates course HTTP operations; catalogue data stays in services and repositories. */
final class CourseController extends BaseController
{
    public function __construct(
        AuthService $auth,
        ThemeRenderer $view,
        RequestStack $requests,
        private readonly CourseService $courses,
        private readonly PlatformAdministrationService $platformAdministration,
        private readonly LearningService $learning
    ) {
        parent::__construct($auth, $view, $requests);
    }

    /** The shared search and pagination dataset. */
    private const DATASET = 'catalogue';

    #[Route('/courses', name: 'course_catalogue_1', methods: ['GET'])]
    #[Route('/courses/category/{slug}', name: 'course_catalogue_2', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function catalogue(): Response
    {
        $category = $this->courses->browsableCategory(trim((string) $this->param('slug')));
        $search = trim((string) ($this->request()->query->get(PlatformAdministrationService::searchParam(self::DATASET), '')));
        $filter = new CatalogueFilter($category === null ? null : (int) $category['id'], [], $search);
        $base = $category === null ? '/courses' : '/courses/category/' . (string) $category['slug'];
        $showcase = $category === null && $search === '';
        // Browsing uses exact membership; a keyword deliberately widens scope to descendants.
        $total = $search !== '' ? $this->courses->catalogueCount($filter)
            : ($category === null ? 0 : $this->courses->categoryCourseTotal((int) $category['id']));
        $pagination = Pagination::catalogue($this->request()->query->get('page'), $total);
        $courses = $showcase ? $this->courses->decorateCards($this->courses->featuredCourses(12))
            : ($search !== '' ? $this->courses->catalogue($filter, $pagination->pageSize, $pagination->offset)
                : $this->courses->categoryCoursePage((int) $category['id'], $pagination->toArray()));

        return $this->render('courses', [
            'title' => $category === null ? 'Course catalogue' : (string) $category['name'] . ' courses',
            'catalogue_navigation' => $this->courses->catalogueNavigation($category),
            'catalogue_search' => $search,
            'courses' => $this->withFavourites($courses),
            'can_favourite' => $this->currentUser() !== null,
            'favourite_return' => $this->requests->getCurrentRequest()?->getRequestUri() ?? $base,
            'workspace' => [
                'heading' => $search !== '' ? 'Search results' : ($category['name'] ?? 'Featured courses'),
                'summary' => $search !== ''
                    ? ($category === null ? 'Searching all published courses.' : 'Searching ' . $category['name'] . ' and all its subcategories.')
                    : ($showcase ? 'A selection to get you started. Choose a category to explore further.' : 'Courses filed directly in this category. Choose a subcategory to explore further.'),
                'paginated' => !$showcase,
                'empty_heading' => $search !== '' ? 'No courses match your search' : 'No courses to show here yet',
                'empty_summary' => $search !== '' ? 'Try another term or clear the search.' : 'Choose a category or subcategory to find a course.',
            ],
            'catalogue_pagination' => PlatformAdministrationService::paginationPayload(
                self::DATASET, $pagination, $base, 'Courses',
                [PlatformAdministrationService::searchParam(self::DATASET) => $search], 'page', 'page_size'
            ),
        ]);
    }

    /** Compatibility destination for existing category-index bookmarks. */
    #[Route('/courses/categories', name: 'course_category_browser', methods: ['GET'])]
    public function categoryBrowser(): Response
    {
        $this->redirect('/courses');
    }

    /**
     * The first row of a list, for the single-row case withFavourites() is handed.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private static function first(array $rows): array
    {
        return $rows[0] ?? [];
    }

    /**
     * Mark which of these courses the signed-in reader has already favourited.
     *
     * Every catalogue surface needs this and each one was doing it for itself, which is how the
     * category fragment previously omitted it: its stars rendered empty however many
     * favourites the reader had.
     *
     * @param list<array<string,mixed>> $courses
     * @return list<array<string,mixed>>
     */
    private function withFavourites(array $courses): array
    {
        $user = $this->currentUser();
        if ($user === null || $courses === []) {
            return $courses;
        }

        $favourites = [];
        foreach ($this->platformAdministration->favourites($user->id) as $item) {
            $favourites[(int) $item['id']] = true;
        }
        foreach ($courses as &$course) {
            $course['is_favourite'] = isset($favourites[(int) $course['id']]);
        }
        unset($course);

        return $courses;
    }

    #[Route('/courses/favourite', name: 'course_toggle_favourite', methods: ['POST'])]
    public function toggleFavourite(): Response
    {
        $this->requireCsrf();
        $user = $this->requirePermission('CATALOGUE.COURSE.FAVOURITE');
        $courseId = max(1, (int) ($_POST['course_id'] ?? 0));

        // A genuine ADMIN may favourite a generated course - the D4 amendment of 2026/09/09 - so
        // the ordinary path no longer refuses one. Provenance can still refuse a write the reader
        // is not entitled to make, and when it does the answer is a message on the page they were
        // on rather than an exception reaching the browser as a 500.
        $failed = false;
        $added = false;
        try {
            $added = $this->platformAdministration->toggleFavourite($user->id, $courseId);
        } catch (RuntimeException) {
            $failed = true;
        }

        $return = (string) ($_POST['return'] ?? '/courses');
        $return = str_starts_with($return, '/') ? $return : '/courses';

        // htmx asked for the star, so it gets the star. Swapping one control in place is the whole
        // point of a toggle: a full page reload to fill in a star loses the reader's scroll position
        // and, on the catalogue, loses their current browsing position.
        if ($this->isHtmxRequest() && !$failed) {
            return $this->renderFragment('partials/course-favourite', [
                'course' => ['id' => $courseId, 'is_favourite' => $added],
                'favourite_return' => $return,
            ]);
        }

        // No flash on success. A flash is a banner at the top of the page, and a banner is a reason
        // to scroll to the top - which on the catalogue means leaving the category the reader had
        // opened. Filling in the star already says the star was filled in; saying it twice, in a
        // place the reader has to travel to, says it worse.
        if ($failed) {
            $this->flash('error', 'That course could not be added to your favourites.');
        }
        $this->redirect($return);
    }


    #[Route('/courses/request', name: 'course_request_course', methods: ['POST'])]
    public function requestCourse(): Response
    {
        $this->requireCsrf();
        $user = $this->requirePermission('CATALOGUE.COURSE.REQUEST');
        $courseId = max(1, (int) ($_POST['course_id'] ?? 0));
        $period = max(86400, (int) ($_POST['access_period_seconds'] ?? 31536000));
        return $this->handle(function () use ($user, $courseId, $period): void {
            $this->platformAdministration->requestCourse($user->id, $courseId, $period, (string) ($_POST['note'] ?? ''));
            $this->flash('success', 'Your course request was sent to your company administrator.');
            $this->redirect('/account/library?tab=requests');
        }, '/courses');
    }


    // Matched last, whatever order the route files are loaded in. `/courses/{slug}` accepts any
    // single word, so it accepts `/courses/tags` too: without a negative priority it swallows every
    // named route under /courses that happens to be declared after it, and the symptom is a 404
    // reading "The course could not be found" on a page that has nothing to do with a course.
    // Declaration order is not a safe place to encode this - moving a controller between two
    // directories was enough to break it once.
    #[Route('/courses/{slug}', name: 'course_detail', requirements: ['slug' => '[a-zA-Z0-9_-]+'], methods: ['GET'], priority: -10)]
    public function detail(): Response
    {
        $slug = (string) $this->param('slug');
        // A course opened from the catalogue is resolved in the catalogue's universe. Reading the
        // detail page in the reader's own universe instead made every course in the generated
        // catalogue a 404 the moment it was clicked, which looks like a broken link rather than
        // like a scope decision.
        try {
            $course = $this->courses->publicCourse($slug);
        } catch (InvalidArgumentException) {
            $course = null;
        }
        if ($course === null) {
            throw new NotFoundHttpException('The course could not be found.');
        }
        $user = $this->currentUser();
        $enrolment = $user !== null
            ? $this->courses->enrolmentForUser($user->id, (int) $course['id'])
            : null;
        // The course's own page had no favourite control at all: a reader could favourite a course
        // from a card in the catalogue and then open it and find nothing saying so, and no way to
        // change their mind without going back.
        $course = self::first($this->withFavourites([$course]));

        return $this->render('course-detail', [
            'title' => (string) $course['title'],
            'course' => $course,
            'has_access' => $enrolment !== null,
            'enrolment_status' => is_array($enrolment) ? (string) $enrolment['status'] : '',
            'can_favourite' => $user !== null,
            'favourite_return' => '/courses/' . (string) $course['slug'],
            'course_content_mode' => true,
        ]);
    }


    #[Route('/course-media/{public_id}', name: 'course_media', requirements: ['public_id' => '[a-zA-Z0-9-]+'], methods: ['GET'])]
    public function media(): Response
    {
        $publicId = (string) $this->param('public_id');
        $media = $this->courses->media($publicId);
        if ($media === null) {
            throw new NotFoundHttpException('The course media file could not be found.');
        }

        $allowed = (bool) $media['is_public'] && (string) $media['course_status'] === 'published';
        $user = $this->currentUser();
        if (!$allowed && $user !== null) {
            $allowed = $this->courses->userCanAccessMedia($user->id, $media, $user->hasPermission('PLATFORM.DASHBOARD.VIEW'));
        }
        if (!$allowed) {
            throw new AccessDeniedHttpException('You do not have access to this course media.');
        }

        $filename = str_replace(["\r", "\n", '"'], '', (string) $media['original_filename']);
        header('Content-Type: ' . (string) $media['mime_type']);
        header('Content-Length: ' . (string) filesize((string) $media['path']));
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: ' . ((string) $media['media_role'] === 'download' ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        readfile((string) $media['path']);
        exit;
    }


    #[Route('/certificates/{public_id}', name: 'course_certificate', requirements: ['public_id' => '[a-zA-Z0-9-]+'], methods: ['GET'])]
    public function certificate(): Response
    {
        $publicId = (string) $this->param('public_id');
        $certificate = $this->learning->certificate($publicId);
        if ($certificate === null) {
            throw new NotFoundHttpException('The certificate could not be found.');
        }
        return $this->render('certificate', [
            'title' => 'Certificate verification',
            'certificate' => $certificate,
        ]);
    }
}