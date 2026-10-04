<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Course\CourseRatingSummary;
use CattoLearning\Course\CourseReviewRepository;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;

/**
 * Recalculates course popularity: loads the eligible courses, aggregates their signals for the
 * window with a fixed number of queries (not one per course), scores them with PopularityModel and
 * replaces the stored snapshot atomically.
 *
 * Signals and where they come from:
 * - discovery interest: distinct viewers of the course page and the public preview in the window.
 *   Learner-reader views are counted separately as context and not scored, so one learner working
 *   through thirty lessons is not thirty expressions of interest. ADMIN previews and editing are
 *   never recorded as views in the first place;
 * - favourites: the favourites table now, not a sum of add and remove events;
 * - purchases and refunds: course_purchased and course_refunded events in the window;
 * - rating: approved reviews only, through CourseReviewRepository, with the platform mean as prior;
 * - momentum: discovery viewers in the latest momentum period against the one before.
 */
final class CoursePopularityCalculator
{
    private const DISCOVERY = [AnalyticsSource::CourseDetail, AnalyticsSource::PublicPreview];

    private readonly PopularityModel $model;

    public function __construct(
        private readonly CoursePopularityRepository $popularity,
        private readonly AnalyticsEventRepository $events,
        private readonly CourseReviewRepository $reviews,
        private readonly ClockInterface $clock,
        ?PopularityModel $model = null,
    ) {
        $this->model = $model ?? PopularityModel::standard();
    }

    public function model(): PopularityModel
    {
        return $this->model;
    }

    /**
     * Calculates every eligible course and replaces the snapshot.
     *
     * @return array{run_id:int,courses:int,calculated_at:DateTimeImmutable,window_start:DateTimeImmutable,window_end:DateTimeImmutable}
     */
    public function recalculate(?DateTimeImmutable $at = null): array
    {
        $end = $at ?? $this->clock->now();
        $start = $end->modify('-' . $this->model->windowDays . ' days');
        $recentStart = $end->modify('-' . $this->model->momentumDays . ' days');
        $previousStart = $recentStart->modify('-' . $this->model->momentumDays . ' days');

        $prior = $this->reviews->globalRatingSummary()->average;
        $scores = $this->model->score($this->metrics($start, $end, $recentStart, $previousStart), $prior);
        $runId = $this->popularity->replace($end, $start, $end, $this->model->parameters($prior), $scores);
        return ['run_id' => $runId, 'courses' => count($scores), 'calculated_at' => $end, 'window_start' => $start, 'window_end' => $end];
    }

    /** @return array<int,CoursePopularityMetrics> keyed by course id */
    private function metrics(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $recentStart, DateTimeImmutable $previousStart): array
    {
        $courses = $this->popularity->eligibleCourses();
        if ($courses === []) { return []; }
        $views = $this->events->uniqueViewersByCourse($start, $end, self::DISCOVERY);
        $readers = $this->events->uniqueViewersByCourse($start, $end, [AnalyticsSource::LearnerReader]);
        $recent = $this->events->uniqueViewersByCourse($recentStart, $end, self::DISCOVERY);
        $previous = $this->events->uniqueViewersByCourse($previousStart, $recentStart, self::DISCOVERY);
        $commerce = $this->events->purchaseTotalsByCourse($start, $end);
        $favourites = $this->events->currentFavouriteCounts();
        $ratings = $this->reviews->ratingSummaries(array_keys($courses));

        $metrics = [];
        foreach ($courses as $id => $offer) {
            $sales = $commerce[$id] ?? ['purchases' => 0, 'purchased_units' => 0, 'refunds' => 0, 'refunded_units' => 0.0];
            $metrics[$id] = new CoursePopularityMetrics(
                courseId: $id,
                offer: $offer,
                uniqueViews: $views[$id] ?? 0,
                learnerReaders: $readers[$id] ?? 0,
                favourites: $favourites[$id] ?? 0,
                purchases: $sales['purchases'],
                purchasedUnits: $sales['purchased_units'],
                refunds: $sales['refunds'],
                refundedUnits: $sales['refunded_units'],
                rating: $ratings[$id] ?? CourseRatingSummary::fromDistribution([]),
                recentViews: $recent[$id] ?? 0,
                previousViews: $previous[$id] ?? 0,
            );
        }
        return $metrics;
    }
}
