<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use InvalidArgumentException;

/**
 * The course popularity model: every parameter in one place, and the arithmetic that turns a set of
 * course metrics into scores. Pure: no database, no clock. standard() holds the current values;
 * change them there, not in the algorithm.
 *
 * A score is out of 100 and is the sum of five component contributions, each a weight × 100 × a
 * bounded 0-1 component:
 *
 *     score = 100 × ( w_views × V + w_favourites × F + w_purchases × P × (1 − R) + w_rating × Q × C + w_momentum × M )
 *
 * V, F, P   views, favourites and purchases, log-normalised across eligible courses:
 *           ln(1 + x) / ln(1 + max x). The best course scores 1, a course with none 0; the log keeps
 *           a large outlier from flattening everyone else, and equal values score equally. When no
 *           course has any, the component is 0 for all.
 * R         the refund ratio: refunded units / (purchased units + refundSmoothing), capped at 1. It
 *           scales the purchase component down rather than going negative, and the smoothing keeps
 *           one refund from wiping out a course with few sales or denting one with many.
 * Q         rating quality: (Bayesian average − 1) / 4, where the Bayesian average pulls a course's
 *           approved-review mean towards the platform mean as if it had `ratingConfidence` reviews
 *           at that mean (CourseRatingSummary::weightedAverage()).
 * C         rating confidence: reviews / (reviews + ratingConfidence). A course with no approved
 *           reviews contributes no rating points; volume and quality both count.
 * M         momentum, rewarding growth only: discovery viewers in the latest `momentumDays`
 *           against the `momentumDays` before them, smoothed so small numbers cannot explode:
 *           growth = (recent + momentumSmoothing) / (previous + momentumSmoothing), and
 *           M = clamp(ln(growth) / ln(fullMomentumGrowth), 0, 1). Flat or falling interest is 0.
 *
 * Free courses have no purchases (accepting a free course is not a purchase event), so their
 * purchase component is 0 and they rank on views, favourites, rating and momentum.
 */
final readonly class PopularityModel
{
    /**
     * @param array<string,float> $weights shares of the score for views, favourites, purchases, rating and momentum, adding up to 1
     */
    public function __construct(
        public int $windowDays,
        public int $momentumDays,
        public array $weights,
        public float $ratingConfidence,
        public float $refundSmoothing,
        public float $momentumSmoothing,
        public float $fullMomentumGrowth,
    ) {
        if ($windowDays < 1 || $windowDays > 366) { throw new InvalidArgumentException('The popularity window is 1 to 366 days.'); }
        if ($momentumDays < 1 || 2 * $momentumDays > $windowDays) { throw new InvalidArgumentException('Both momentum periods must fit inside the popularity window.'); }
        $expected = ['views', 'favourites', 'purchases', 'rating', 'momentum'];
        if (array_keys($weights) !== $expected) { throw new InvalidArgumentException('Popularity weights are views, favourites, purchases, rating and momentum, in that order.'); }
        foreach ($weights as $name => $weight) {
            if ($weight < 0 || $weight > 1) { throw new InvalidArgumentException('The ' . $name . ' weight must be between 0 and 1.'); }
        }
        if (abs(array_sum($weights) - 1.0) > 1e-9) { throw new InvalidArgumentException('Popularity weights must add up to 1.'); }
        if ($ratingConfidence <= 0 || $refundSmoothing <= 0 || $momentumSmoothing <= 0) { throw new InvalidArgumentException('Confidence and smoothing values must be positive.'); }
        if ($fullMomentumGrowth <= 1) { throw new InvalidArgumentException('Full momentum must need growth above 1.'); }
    }

    /** The current model. These are starting values, chosen to be changed here when evidence suggests better ones. */
    public static function standard(): self
    {
        return new self(
            windowDays: 30,
            momentumDays: 7,
            weights: ['views' => 0.15, 'favourites' => 0.20, 'purchases' => 0.35, 'rating' => 0.20, 'momentum' => 0.10],
            ratingConfidence: 10.0,
            refundSmoothing: 5.0,
            momentumSmoothing: 5.0,
            fullMomentumGrowth: 3.0,
        );
    }

    /**
     * Scores and ranks a set of courses against each other.
     *
     * @param array<int,CoursePopularityMetrics> $metrics
     * @param ?float $ratingPrior the platform's approved-review mean, or null when nothing is reviewed
     * @return list<CoursePopularityScore> best first; equal scores share a rank
     */
    public function score(array $metrics, ?float $ratingPrior): array
    {
        $maxViews = 0; $maxFavourites = 0; $maxPurchases = 0;
        foreach ($metrics as $metric) {
            $maxViews = max($maxViews, $metric->uniqueViews);
            $maxFavourites = max($maxFavourites, $metric->favourites);
            $maxPurchases = max($maxPurchases, $metric->purchases);
        }
        $scores = [];
        foreach ($metrics as $metric) {
            $refundRatio = $this->refundRatio($metric->purchasedUnits, $metric->refundedUnits);
            $purchase = self::normalise($metric->purchases, $maxPurchases);
            $weighted = $metric->rating->count > 0 ? $metric->rating->weightedAverage($ratingPrior, $this->ratingConfidence) : null;
            $growth = $this->momentumGrowth($metric->recentViews, $metric->previousViews);
            $points = [
                'views' => $this->points('views', self::normalise($metric->uniqueViews, $maxViews)),
                'favourites' => $this->points('favourites', self::normalise($metric->favourites, $maxFavourites)),
                'purchases' => $this->points('purchases', $purchase * (1 - $refundRatio)),
                'refund_penalty' => $this->points('purchases', $purchase * $refundRatio),
                'rating' => $this->points('rating', $weighted === null ? 0.0 : self::ratingQuality($weighted) * $metric->rating->confidence($this->ratingConfidence)),
                'momentum' => $this->points('momentum', $this->momentum($growth)),
            ];
            $scores[] = [
                'metric' => $metric, 'points' => $points, 'refund_ratio' => $refundRatio, 'weighted' => $weighted, 'growth' => $growth,
                'score' => round($points['views'] + $points['favourites'] + $points['purchases'] + $points['rating'] + $points['momentum'], 3),
            ];
        }
        usort($scores, static fn(array $a, array $b): int => [$b['score'], $a['metric']->courseId] <=> [$a['score'], $b['metric']->courseId]);
        $ranked = []; $rank = 0; $previous = null;
        foreach ($scores as $position => $row) {
            if ($row['score'] !== $previous) { $rank = $position + 1; $previous = $row['score']; }
            $ranked[] = new CoursePopularityScore($row['metric'], $rank, $row['score'], $row['points']['views'], $row['points']['favourites'],
                $row['points']['purchases'], $row['points']['refund_penalty'], $row['points']['rating'], $row['points']['momentum'],
                $row['refund_ratio'], $row['weighted'], $row['growth']);
        }
        return $ranked;
    }

    /** ln(1 + value) / ln(1 + max): 0 for none, 1 for the highest, compressed between. */
    public static function normalise(int|float $value, int|float $max): float
    {
        if ($max <= 0 || $value <= 0) { return 0.0; }
        return min(1.0, log1p($value) / log1p($max));
    }

    /** A 1-5 star rating on a 0-1 scale. */
    public static function ratingQuality(float $rating): float
    {
        return max(0.0, min(1.0, ($rating - 1) / 4));
    }

    /** Refunded units over purchased units plus smoothing, at most 1. */
    public function refundRatio(int $purchasedUnits, float $refundedUnits): float
    {
        if ($refundedUnits <= 0) { return 0.0; }
        return min(1.0, $refundedUnits / ($purchasedUnits + $this->refundSmoothing));
    }

    /** (recent + smoothing) / (previous + smoothing): 1 when interest is flat or absent. */
    public function momentumGrowth(int $recent, int $previous): float
    {
        return ($recent + $this->momentumSmoothing) / ($previous + $this->momentumSmoothing);
    }

    /** Growth on a 0-1 scale: 0 for flat or falling, 1 at fullMomentumGrowth or more. */
    public function momentum(float $growth): float
    {
        return $growth <= 1 ? 0.0 : min(1.0, log($growth) / log($this->fullMomentumGrowth));
    }

    /**
     * The parameters a run was calculated with, for the snapshot.
     *
     * @return array<string,mixed>
     */
    public function parameters(?float $ratingPrior): array
    {
        return [
            'window_days' => $this->windowDays, 'momentum_days' => $this->momentumDays, 'weights' => $this->weights,
            'rating_prior' => $ratingPrior === null ? null : round($ratingPrior, 4), 'rating_confidence' => $this->ratingConfidence,
            'refund_smoothing' => $this->refundSmoothing, 'momentum_smoothing' => $this->momentumSmoothing, 'full_momentum_growth' => $this->fullMomentumGrowth,
            'normalisation' => 'ln(1+x)/ln(1+max)',
        ];
    }

    private function points(string $component, float $value): float
    {
        return round(100 * $this->weights[$component] * max(0.0, min(1.0, $value)), 3);
    }
}
