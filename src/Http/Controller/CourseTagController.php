<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Course\CatalogueFilter;
use CattoLearning\Course\CourseService;
use CattoLearning\Support\ListView;
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
        private readonly PlatformAdministrationService $platformAdministration,
        private readonly \CattoLearning\Mailing\MailingConsents $mailing
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
        // Cards or a list, as the reader chose on the catalogue or here (owner, 2026-10-26).
        $view = ListView::chosen(self::DATASET, $request->query->all(), $request->cookies->all());
        ListView::rememberAsked(self::DATASET, $request->query->all());

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
            'tag_index' => self::weighted($this->courses->tagIndex()),
            // The sphere animates the 30 most used tags. More spinning at once is a ball of
            // overlapping words nobody can read or click; the full list stays beside it, so nothing
            // is hidden - only the animation is bounded.
            'cloud_limit' => 30,
            'selected_tag' => $tag ?? [],
            'showing_courses' => $showing,
            'courses' => $courses,
            'can_favourite' => $identity !== null,
            'favourite_return' => $request->getRequestUri(),
            'workspace' => [
                'heading' => $search !== '' ? 'Search results' : ($tag !== null ? 'Courses tagged ' . $tag['name'] : 'Choose a tag'),
                'summary' => $tag !== null ? 'Courses carrying ' . $tag['name'] . ($search !== '' ? ' matching your search.' : '.') : 'Search all courses or choose a label above.',
                'view' => $view,
                'view_switch' => ListView::switcher(self::DATASET, $view, $base, [PlatformAdministrationService::searchParam(self::DATASET) => $search]),
                'paginated' => $showing,
                'empty_heading' => $showing ? 'No courses match this selection' : 'Explore the catalogue by tag',
                'empty_summary' => $showing ? 'Try another term or choose another tag.' : 'Choose a tag above or search for a course.',
                // The tag shown can be followed (mailing, step 1; owner, 2026-10-26).
                'follow' => $tag === null ? null : ['kind' => 'tag', 'id' => (int) $tag['id'], 'name' => (string) $tag['name'], 'following' => $identity !== null && $this->mailing->isSubscribed($identity->id, 'tag', (int) $tag['id']), 'signed_in' => $identity !== null, 'return' => $base . '#follow'] + $this->mailing->followChoice($identity === null ? 0 : $identity->id),
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
     * The vocabulary, sized for a cloud: the more courses a tag labels, the bigger it is, so the most
     * used tags stand out at a glance (owner, 2026-10-18).
     *
     * Five steps on a logarithmic scale between the least and the most used tag. A linear scale put
     * every tag in the smallest step as soon as one tag ran away with the catalogue; on a log scale a
     * tag with 2 courses, one with 20 and one with 200 are three clearly different sizes. When every
     * tag labels the same number of courses they are all the middle size.
     *
     * @param list<array<string,mixed>> $tags
     * @return list<array<string,mixed>>
     */
    public static function weighted(array $tags): array
    {
        $counts = array_map(static fn(array $tag): int => max(1, (int) $tag['course_count']), $tags);
        $low = $counts === [] ? 1 : min($counts);
        $high = $counts === [] ? 1 : max($counts);
        foreach ($tags as &$tag) {
            $count = max(1, (int) $tag['course_count']);
            $tag['weight'] = $high === $low ? 3 : 1 + (int) round(4 * (log($count) - log($low)) / (log($high) - log($low)));
        }
        unset($tag);

        return $tags;
    }
}
