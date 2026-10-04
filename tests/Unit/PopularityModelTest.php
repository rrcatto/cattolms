<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseRatingSummary;
use CattoLearning\Course\Popularity\CoursePopularityMetrics;
use CattoLearning\Course\Popularity\CoursePopularityScore;
use CattoLearning\Course\Popularity\PopularityModel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The popularity model's arithmetic without a database: normalisation, the confidence-weighted
 * rating, the bounded refund penalty, smoothed momentum, free courses, ranking and the guards on
 * its parameters.
 */
final class PopularityModelTest extends TestCase
{
    private PopularityModel $model;

    protected function setUp(): void
    {
        $this->model = PopularityModel::standard();
    }

    public function testTheStandardWeightsAreTheDocumentedShares(): void
    {
        self::assertSame(['views' => 0.15, 'favourites' => 0.20, 'purchases' => 0.35, 'rating' => 0.20, 'momentum' => 0.10], $this->model->weights);
        self::assertSame(30, $this->model->windowDays);
        self::assertSame(7, $this->model->momentumDays);
    }

    public function testNormalisationIsBoundedForZeroEqualOutlierAndSingleValues(): void
    {
        self::assertSame(0.0, PopularityModel::normalise(0, 0), 'Nothing anywhere is zero, not a division by zero.');
        self::assertSame(0.0, PopularityModel::normalise(0, 50));
        self::assertSame(1.0, PopularityModel::normalise(50, 50), 'The leader scores 1.');
        self::assertSame(1.0, PopularityModel::normalise(7, 7), 'Equal values score equally.');
        $outlier = PopularityModel::normalise(100, 100000);
        self::assertGreaterThan(0.35, $outlier, 'A large outlier compresses, rather than flattens, the rest.');
        self::assertLessThan(1.0, $outlier);
        self::assertLessThan(PopularityModel::normalise(1000, 100000), $outlier, 'Order is kept.');
    }

    public function testAnEmptySetScoresNothing(): void
    {
        self::assertSame([], $this->model->score([], null));
    }

    public function testASingleCourseWithNoDataScoresZeroWithoutFailing(): void
    {
        $scores = $this->model->score([1 => self::metrics(1)], null);
        self::assertCount(1, $scores);
        self::assertSame(0.0, $scores[0]->score);
        self::assertSame(1, $scores[0]->rank);
        self::assertNull($scores[0]->weightedRating);
    }

    public function testASingleCourseWithSignalsTakesFullComponentPoints(): void
    {
        [$score] = $this->model->score([1 => self::metrics(1, views: 3, favourites: 2, purchases: 1, units: 1)], null);
        self::assertSame(15.0, $score->viewPoints);
        self::assertSame(20.0, $score->favouritePoints);
        self::assertSame(35.0, $score->purchasePoints);
        self::assertSame(70.0, $score->score);
    }

    public function testCoursesWithIdenticalValuesShareARank(): void
    {
        $scores = $this->model->score([5 => self::metrics(5, views: 4), 3 => self::metrics(3, views: 4), 9 => self::metrics(9, views: 1)], null);
        self::assertSame([3, 5, 9], array_map(static fn(CoursePopularityScore $s): int => $s->metrics->courseId, $scores), 'Ties are listed by course id.');
        self::assertSame([1, 1, 3], array_map(static fn(CoursePopularityScore $s): int => $s->rank, $scores));
        self::assertSame($scores[0]->score, $scores[1]->score);
    }

    public function testTheComponentsAddUpToTheScore(): void
    {
        foreach ($this->model->score(self::mixedSet(), 4.2) as $score) {
            self::assertEqualsWithDelta($score->viewPoints + $score->favouritePoints + $score->purchasePoints + $score->ratingPoints + $score->momentumPoints, $score->score, 0.0011);
            self::assertGreaterThanOrEqual(0.0, $score->score);
            self::assertLessThanOrEqual(100.0, $score->score);
        }
    }

    public function testOneFiveStarReviewDoesNotBeatHundredsOfStrongReviews(): void
    {
        $prior = 4.0;
        $one = CourseRatingSummary::fromDistribution([5 => 1]);
        $many = CourseRatingSummary::fromDistribution([5 => 400, 4 => 100]);
        self::assertEqualsWithDelta(4.8, $many->average, 1e-9);
        self::assertEqualsWithDelta((10 * 4.0 + 5.0) / 11, $one->weightedAverage($prior, 10.0), 1e-9);
        self::assertGreaterThan($one->weightedAverage($prior, 10.0), $many->weightedAverage($prior, 10.0));

        $scores = $this->model->score([1 => self::metrics(1, rating: $one), 2 => self::metrics(2, rating: $many)], $prior);
        self::assertSame(2, $scores[0]->metrics->courseId, '4.8 from 500 outranks 5.0 from one.');
        self::assertGreaterThan($scores[1]->ratingPoints * 5, $scores[0]->ratingPoints);
    }

    public function testTheRatingPriorAndNoReviewCases(): void
    {
        $none = CourseRatingSummary::fromDistribution([]);
        self::assertSame(4.2, $none->weightedAverage(4.2, 10.0), 'With no reviews the weighted rating is the prior.');
        self::assertNull($none->weightedAverage(null, 10.0), 'With no reviews anywhere there is nothing to weight.');
        self::assertSame(0.0, $none->confidence(10.0));
        $three = CourseRatingSummary::fromDistribution([3 => 2, 5 => 2]);
        self::assertSame(4.0, $three->weightedAverage(null, 10.0), 'Without a prior the course mean stands.');
        self::assertEqualsWithDelta(0.5, CourseRatingSummary::fromDistribution([4 => 10])->confidence(10.0), 1e-9);

        [$score] = $this->model->score([1 => self::metrics(1)], 4.6);
        self::assertSame(0.0, $score->ratingPoints, 'An unreviewed course earns no rating points from the prior.');
        self::assertNull($score->weightedRating);
    }

    public function testRefundsReducePurchasesBoundedly(): void
    {
        self::assertSame(0.0, $this->model->refundRatio(10, 0));
        self::assertEqualsWithDelta(1 / 1005, $this->model->refundRatio(1000, 1), 1e-9, 'One refund barely dents a course selling a thousand.');
        self::assertEqualsWithDelta(1 / 6, $this->model->refundRatio(1, 1), 1e-9, 'Smoothing keeps one refund of one sale from wiping it out.');
        self::assertSame(1.0, $this->model->refundRatio(0, 40), 'The ratio never exceeds 1, so the component never goes negative.');

        $scores = $this->model->score([
            1 => self::metrics(1, purchases: 50, units: 50),
            2 => self::metrics(2, purchases: 50, units: 50, refunds: 25, refunded: 25.0),
        ], null);
        [$clean, $refunded] = $scores;
        self::assertSame(1, $clean->metrics->courseId);
        self::assertSame(35.0, $clean->purchasePoints);
        self::assertSame(0.0, $clean->refundPenaltyPoints);
        self::assertEqualsWithDelta(35 * (1 - 25 / 55), $refunded->purchasePoints, 0.001);
        self::assertEqualsWithDelta(35.0, $refunded->purchasePoints + $refunded->refundPenaltyPoints, 0.002, 'The penalty is the share of purchase points removed.');
    }

    public function testMomentumIsSmoothedAndRewardsGrowthOnly(): void
    {
        self::assertSame(1.0, $this->model->momentumGrowth(0, 0));
        self::assertSame(0.0, $this->model->momentum($this->model->momentumGrowth(0, 0)), 'No activity is no momentum.');
        self::assertSame(0.0, $this->model->momentum($this->model->momentumGrowth(3, 30)), 'Falling interest is not penalised twice.');
        $fromNothing = $this->model->momentum($this->model->momentumGrowth(1, 0));
        self::assertGreaterThan(0.0, $fromNothing);
        self::assertLessThan(0.3, $fromNothing, 'One new viewer after none is not an explosion.');
        self::assertSame(1.0, $this->model->momentum($this->model->momentumGrowth(1000, 0)), 'Momentum is capped.');
    }

    public function testMomentumCanSeparateCloseCourses(): void
    {
        $scores = $this->model->score([
            1 => self::metrics(1, views: 20, favourites: 5, recent: 3, previous: 12),
            2 => self::metrics(2, views: 20, favourites: 5, recent: 12, previous: 3),
        ], null);
        self::assertSame(2, $scores[0]->metrics->courseId);
        self::assertGreaterThan(0.0, $scores[0]->momentumPoints);
        self::assertLessThanOrEqual(10.0, $scores[0]->momentumPoints, 'Momentum never outweighs its share.');
    }

    public function testViewsAloneDoNotOutrankStrongConversion(): void
    {
        $scores = $this->model->score([
            1 => self::metrics(1, views: 10000),
            2 => self::metrics(2, views: 300, favourites: 40, purchases: 60, units: 60, rating: CourseRatingSummary::fromDistribution([5 => 30, 4 => 10])),
        ], 4.3);
        self::assertSame(2, $scores[0]->metrics->courseId);
        self::assertLessThanOrEqual(15.0, $scores[1]->score, 'Views can earn at most their weight.');
    }

    public function testAFreeCourseRanksWithoutPurchases(): void
    {
        $scores = $this->model->score([
            1 => self::metrics(1, offer: 'free', views: 50, favourites: 30, rating: CourseRatingSummary::fromDistribution([5 => 20])),
            2 => self::metrics(2, views: 5, purchases: 1, units: 1),
        ], 4.0);
        $free = $scores[0]->metrics->courseId === 1 ? $scores[0] : $scores[1];
        self::assertSame(0.0, $free->purchasePoints);
        self::assertGreaterThan(0.0, $free->score);
        self::assertSame(1, $free->rank, 'Strong interest and ratings outrank a single sale.');
    }

    public function testParametersAreValidated(): void
    {
        $valid = ['views' => 0.15, 'favourites' => 0.20, 'purchases' => 0.35, 'rating' => 0.20, 'momentum' => 0.10];
        $cases = [
            'weights not adding up' => fn() => new PopularityModel(30, 7, ['views' => 0.5] + $valid, 10, 5, 5, 3),
            'negative weight' => fn() => new PopularityModel(30, 7, ['views' => -0.05, 'favourites' => 0.40] + $valid, 10, 5, 5, 3),
            'missing weight' => fn() => new PopularityModel(30, 7, ['views' => 0.25, 'favourites' => 0.20, 'purchases' => 0.35, 'rating' => 0.20], 10, 5, 5, 3),
            'momentum periods outside the window' => fn() => new PopularityModel(10, 7, $valid, 10, 5, 5, 3),
            'zero confidence' => fn() => new PopularityModel(30, 7, $valid, 0, 5, 5, 3),
            'growth of one' => fn() => new PopularityModel(30, 7, $valid, 10, 5, 5, 1),
        ];
        foreach ($cases as $case => $build) {
            try {
                $build();
                self::fail($case . ' must be refused.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(['window_days', 'momentum_days', 'weights', 'rating_prior', 'rating_confidence', 'refund_smoothing', 'momentum_smoothing', 'full_momentum_growth', 'normalisation'], array_keys($this->model->parameters(4.25)));
    }

    /** @return array<int,CoursePopularityMetrics> */
    private static function mixedSet(): array
    {
        return [
            1 => self::metrics(1, views: 120, favourites: 8, purchases: 4, units: 4, refunds: 1, refunded: 0.5, rating: CourseRatingSummary::fromDistribution([5 => 3, 2 => 1]), recent: 40, previous: 20),
            2 => self::metrics(2, offer: 'free', views: 30, favourites: 30, rating: CourseRatingSummary::fromDistribution([4 => 12])),
            3 => self::metrics(3),
            4 => self::metrics(4, views: 2000, purchases: 1, units: 20, recent: 0, previous: 900),
        ];
    }

    private static function metrics(int $id, string $offer = 'paid', int $views = 0, int $favourites = 0, int $purchases = 0, int $units = 0, int $refunds = 0, float $refunded = 0.0, ?CourseRatingSummary $rating = null, int $recent = 0, int $previous = 0): CoursePopularityMetrics
    {
        return new CoursePopularityMetrics($id, $offer, $views, 0, $favourites, $purchases, $units, $refunds, $refunded, $rating ?? CourseRatingSummary::fromDistribution([]), $recent, $previous);
    }
}
