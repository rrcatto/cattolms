<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

/**
 * One course's calculated popularity: its rank, its score out of 100, the points each component
 * contributed (they add up to the score; purchase points are after the refund penalty) and the
 * derived values behind them.
 */
final readonly class CoursePopularityScore
{
    public function __construct(
        public CoursePopularityMetrics $metrics,
        public int $rank,
        public float $score,
        public float $viewPoints,
        public float $favouritePoints,
        public float $purchasePoints,
        public float $refundPenaltyPoints,
        public float $ratingPoints,
        public float $momentumPoints,
        public float $refundRatio,
        public ?float $weightedRating,
        public float $momentumGrowth,
    ) {
    }
}
