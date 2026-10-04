<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Course\Popularity\CoursePopularityMetrics;
use CattoLearning\Course\Popularity\CoursePopularityRepository;
use CattoLearning\Support\Pagination;
use CattoLearning\Support\SortOrder;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ADMIN analytics: a diagnostic list of recorded analytics events, so ADMIN can confirm what is
 * being recorded without querying the database, and the course popularity report, which shows the
 * stored ranking with every metric and component so ADMIN can see why a course ranks where it does.
 */
final class AdminAnalyticsController extends BaseController
{
    private const PAGE_SIZE = 50;
    private const POPULARITY = '/admin/reports/popularity';
    /** Popularity report columns in table order; the share of width follows the bar. */
    private const POPULARITY_COLUMNS = [
        'rank' => 'Rank|6', 'course' => 'Course|22', 'score' => 'Score|8', 'views' => 'Unique views', 'favourites' => 'Favourites',
        'purchases' => 'Purchases', 'refunds' => 'Refunds', 'rating' => 'Rating', 'reviews' => 'Reviews', 'momentum' => 'Momentum',
    ];

    public function __construct(
        AuthService $auth,
        ThemeRenderer $view,
        RequestStack $requests,
        private readonly AnalyticsEventRepository $events,
        private readonly ClockInterface $clock,
        private readonly CoursePopularityRepository $popularity,
    ) {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/analytics/events', name: 'admin_analytics_events', methods: ['GET'])]
    public function events(): Response
    {
        $this->requirePermission('PLATFORM.REPORT.VIEW');
        $type = AnalyticsEventType::tryFrom((string) ($_GET['type'] ?? ''))?->value;
        $course = ctype_digit((string) ($_GET['course_id'] ?? '')) && (int) $_GET['course_id'] > 0 ? (int) $_GET['course_id'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = $this->events->recentCount($type, $course);
        $rows = array_map(static fn(array $row): array => $row + ['metadata_pairs' => self::pairs((string) $row['metadata'])], $this->events->recent($type, $course, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE));
        $query = array_filter(['type' => $type, 'course_id' => $course]);
        return $this->render('admin-analytics-events', [
            'title' => 'Analytics events',
            'events' => $rows,
            'counts' => $this->events->countsByType($this->clock->now()->modify('-30 days')),
            'event_types' => array_map(static fn(AnalyticsEventType $case): string => $case->value, AnalyticsEventType::cases()),
            'filter' => ['type' => $type ?? '', 'course_id' => $course === null ? '' : (string) $course],
            'total' => $total,
            'page' => $page,
            'newer_href' => $page > 1 ? '/admin/analytics/events?' . http_build_query($query + ['page' => $page - 1]) : '',
            'older_href' => $page * self::PAGE_SIZE < $total ? '/admin/analytics/events?' . http_build_query($query + ['page' => $page + 1]) : '',
        ]);
    }

    #[Route('/admin/reports/popularity', name: 'admin_course_popularity', methods: ['GET'])]
    public function popularity(): Response
    {
        $this->requirePermission('PLATFORM.REPORT.VIEW');
        $search = PlatformAdministrationService::searchTerm('popularity', $_GET);
        $offer = in_array($_GET['offer'] ?? '', CoursePopularityMetrics::OFFERS, true) ? (string) $_GET['offer'] : '';
        $sort = SortOrder::create($_GET['popularity_sort'] ?? null, $_GET['popularity_dir'] ?? null, array_keys(CoursePopularityRepository::SORTS), 'rank');
        $pagination = Pagination::create($_GET[PlatformAdministrationService::pageParam('popularity')] ?? null, $_GET[PlatformAdministrationService::sizeParam('popularity')] ?? null, $this->popularity->rankedCount($search, $offer, false));
        $filters = [PlatformAdministrationService::searchParam('popularity') => $search, 'offer' => $offer];
        // Larger is better for every count, so those columns sort highest first on the first click.
        $natural = array_fill_keys(['score', 'views', 'favourites', 'purchases', 'refunds', 'rating', 'reviews', 'momentum'], SortOrder::DESCENDING);
        return $this->render('admin-course-popularity', [
            'title' => 'Course popularity',
            'run' => $this->popularity->currentRun(),
            'courses' => $this->popularity->ranked($search, $offer, false, $sort, $pagination->pageSize, $pagination->offset),
            'popularity_search' => $search,
            'offer_filters' => self::offerFilters($offer, [PlatformAdministrationService::searchParam('popularity') => $search] + $sort->toPrefixedArray('popularity')),
            'popularity_pagination' => PlatformAdministrationService::paginationPayload('popularity', $pagination, self::POPULARITY, 'Course popularity', $filters + $sort->toPrefixedArray('popularity')),
        ] + PlatformAdministrationService::sortViewFor('popularity', $sort, self::POPULARITY, self::POPULARITY_COLUMNS, array_keys(self::POPULARITY_COLUMNS), $filters, $natural));
    }

    #[Route('/admin/reports/popularity/{id}', name: 'admin_course_popularity_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function popularityDetail(): Response
    {
        $this->requirePermission('PLATFORM.REPORT.VIEW');
        $course = $this->popularity->forCourse((int) $this->param('id'));
        if ($course === null) { throw $this->notFound('This course has no popularity: it is not published, or popularity has not been calculated since it was.'); }
        return $this->render('admin-course-popularity-detail', ['title' => 'Popularity: ' . $course['title'], 'course' => $course, 'run' => $this->popularity->currentRun()]);
    }

    /**
     * The offer filter chips above the popularity report, each keeping the search and sort.
     *
     * @param array<string,string> $carried
     * @return list<array{label:string,href:string,active:bool}>
     */
    private static function offerFilters(string $selected, array $carried): array
    {
        $carried = array_filter($carried, static fn(string $value): bool => $value !== '');
        $chips = [];
        foreach (['' => 'All courses', 'paid' => 'Paid', 'free' => 'Free', 'none' => 'No active price'] as $offer => $label) {
            $query = $carried + ($offer === '' ? [] : ['offer' => $offer]);
            $chips[] = ['label' => $label, 'href' => self::POPULARITY . ($query === [] ? '' : '?' . http_build_query($query)), 'active' => $selected === $offer];
        }
        return $chips;
    }

    /** @return list<string> */
    private static function pairs(string $json): array
    {
        $data = json_decode($json, true);
        $pairs = [];
        foreach (is_array($data) ? $data : [] as $key => $value) {
            $pairs[] = $key . ': ' . (is_array($value) ? implode(', ', array_map('strval', $value)) : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }
        return $pairs;
    }
}
