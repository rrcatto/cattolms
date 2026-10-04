<?php

declare(strict_types=1);

namespace CattoLearning\Course\Popularity;

use CattoLearning\Course\CourseRatingSummary;
use InvalidArgumentException;

/**
 * The source metrics of one course for one popularity window, as aggregated from the analytics
 * events, the favourites table, approved reviews and the course's active price variants.
 */
final readonly class CoursePopularityMetrics
{
    public const OFFERS = ['paid', 'free', 'none'];

    /**
     * @param string $offer paid when an active variant has a price, free when every active variant is free, none without an active variant
     * @param int   $uniqueViews    distinct discovery viewers (course page and public preview) in the window
     * @param int   $learnerReaders distinct learners in the reader in the window: context only, not scored
     * @param int   $favourites     people who favourite the course now
     * @param int   $purchases      paid order lines in the window
     * @param int   $purchasedUnits units those lines bought
     * @param int   $refunds        refunds approved in the window
     * @param float $refundedUnits  units those refunds returned, partial refunds as fractions
     * @param int   $recentViews    distinct discovery viewers in the latest momentum period
     * @param int   $previousViews  distinct discovery viewers in the momentum period before it
     */
    public function __construct(
        public int $courseId,
        public string $offer,
        public int $uniqueViews,
        public int $learnerReaders,
        public int $favourites,
        public int $purchases,
        public int $purchasedUnits,
        public int $refunds,
        public float $refundedUnits,
        public CourseRatingSummary $rating,
        public int $recentViews,
        public int $previousViews,
    ) {
        if (!in_array($offer, self::OFFERS, true)) { throw new InvalidArgumentException('Unknown course offer: ' . $offer); }
        foreach ([$uniqueViews, $learnerReaders, $favourites, $purchases, $purchasedUnits, $refunds, $recentViews, $previousViews] as $count) {
            if ($count < 0) { throw new InvalidArgumentException('Popularity metrics cannot be negative.'); }
        }
        if ($refundedUnits < 0) { throw new InvalidArgumentException('Popularity metrics cannot be negative.'); }
    }
}
