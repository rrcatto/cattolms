<?php

declare(strict_types=1);

namespace CattoLearning\Http\Controller;

use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Auth\AuthService;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A diagnostic list of recorded analytics events, so ADMIN can confirm what is being recorded
 * without querying the database. Not a reporting dashboard: counts by type for the last 30 days and
 * the newest events, filterable by type and course.
 */
final class AdminAnalyticsController extends BaseController
{
    private const PAGE_SIZE = 50;

    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly AnalyticsEventRepository $events, private readonly ClockInterface $clock)
    {
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
