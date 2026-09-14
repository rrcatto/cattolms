<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Course\CatalogueFilter;
use CattoLearning\Course\CourseService;
use CattoLearning\Support\Pagination;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Sole HTTP owner of tag discovery and tag-scoped course results. */
final class CourseTagController extends BaseController
{
    private const DATASET = 'catalogue';

    public function __construct(
        AuthService $auth,
        ThemeRenderer $view,
        RequestStack $requests,
        private readonly CourseService $courses,
        private readonly PlatformAdministrationService $platformAdministration
    ) {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/courses/tags', name: 'course_tags', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, '');
    }

    #[Route('/courses/tag/{slug}', name: 'course_tag', methods: ['GET'], requirements: ['slug' => '[a-z0-9-]+'])]
    public function tag(Request $request, string $slug): Response
    {
        return $this->page($request, $slug);
    }

    private function page(Request $request, string $slug): Response
    {
        $identity = $this->currentUser();
        $selectedTags = $this->courses->activeTagsBySlug(array_filter([$slug]));
        $search = trim((string) $request->query->get(PlatformAdministrationService::searchParam(self::DATASET), ''));

        $filter = new CatalogueFilter(
            null,
            array_map(static fn(array $tag): int => (int) $tag['id'], $selectedTags),
            $search
        );

        $tag = $selectedTags[0] ?? null;
        $base = $tag !== null ? '/courses/tag/' . (string) $tag['slug'] : '/courses/tags';

        // Courses are read only once a tag is chosen or a term typed. Landing on the page is a
        // question about the vocabulary, and answering it with the whole catalogue would be a
        // slower page that answered something nobody asked.
        $showing = $selectedTags !== [] || $search !== '';
        $pagination = Pagination::catalogue(
            $request->query->get('page'),
            $showing ? $this->courses->catalogueCount($filter) : 0
        );
        $courses = $showing
            ? $this->courses->catalogue($filter, $pagination->pageSize, $pagination->offset)
            : [];

        if ($identity !== null && $courses !== []) {
            $favourites = [];
            foreach ($this->platformAdministration->favourites($identity->id) as $item) {
                $favourites[(int) $item['id']] = true;
            }
            foreach ($courses as &$course) {
                $course['is_favourite'] = isset($favourites[(int) $course['id']]);
            }
            unset($course);
        }

        return $this->render('course-tags', [
            'title' => $tag !== null ? 'Courses tagged ' . (string) $tag['name'] : 'Browse by tag',
            'page_kicker' => 'Every label in the catalogue',
            'tag_index' => $this->weighted($this->courses->tagIndex()),
            // The sphere animates a bounded subset of tags. All 378 spinning at once is a ball of
            // overlapping words nobody can read or click; the full list stays beside it as the
            // fallback, so nothing is hidden - only the animation is bounded.
            'cloud_limit' => 80,
            'selected_tag' => $tag ?? [],
            'showing_courses' => $showing,
            'courses' => $courses,
            'can_favourite' => $identity !== null,
            'favourite_return' => $request->getRequestUri(),
            'workspace' => [
                'heading' => $search !== '' ? 'Search results' : ($tag !== null ? 'Courses tagged ' . $tag['name'] : 'Choose a tag'),
                'summary' => $tag !== null ? 'Courses carrying ' . $tag['name'] . ($search !== '' ? ' matching your search.' : '.') : 'Search all courses or choose a label above.',
                'paginated' => $showing,
                'empty_heading' => $showing ? 'No courses match this selection' : 'Explore the catalogue by tag',
                'empty_summary' => $showing ? 'Try another term or choose another tag.' : 'Choose a tag above or search for a course.',
            ],
            'catalogue_search' => $search,
            'universe_query' => '',
            'universe_amp' => '',
            'catalogue_pagination' => PlatformAdministrationService::paginationPayload(
                'catalogue',
                $pagination,
                $base,
                'Courses',
                [PlatformAdministrationService::searchParam(self::DATASET) => $search],
                'page',
                'page_size'
            ),
        ] + $pagination->toArray());
    }

    /**
     * The vocabulary, sized for a cloud.
     *
     * Five steps rather than a continuous scale: a cloud sized by raw proportion is unreadable the
     * moment one tag runs away with the catalogue, and a reader cannot tell 14pt from 15pt anyway.
     *
     * @param list<array<string,mixed>> $tags
     * @return list<array<string,mixed>>
     */
    private function weighted(array $tags): array
    {
        $largest = 0;
        foreach ($tags as $tag) {
            $largest = max($largest, (int) $tag['course_count']);
        }
        foreach ($tags as &$tag) {
            $count = (int) $tag['course_count'];
            $tag['weight'] = $largest === 0 || $count === 0 ? 0 : (int) ceil($count / $largest * 5);
        }
        unset($tag);

        return $tags;
    }
}
