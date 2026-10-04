<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/**
 * A course's rating, from approved reviews only: how many there are, their mean and how they are
 * spread across one to five stars. This is the input a later popularity calculation consumes; it
 * deliberately carries the count and distribution as well as the mean, so that a confidence-aware
 * score can tell 5.0 from one review apart from 4.8 from five hundred. The summary itself applies no
 * weighting; weightedAverage() and confidence() are the confidence-aware views of it.
 */
final readonly class CourseRatingSummary
{
    /** @param array<int,int> $distribution review count per star, keys 5 down to 1 */
    private function __construct(public int $count, public ?float $average, public array $distribution)
    {
    }

    /** @param array<int,int> $counts review count per star rating */
    public static function fromDistribution(array $counts): self
    {
        $distribution = [];
        $count = 0;
        $total = 0;
        foreach ([5, 4, 3, 2, 1] as $stars) {
            $reviews = max(0, (int) ($counts[$stars] ?? 0));
            $distribution[$stars] = $reviews;
            $count += $reviews;
            $total += $stars * $reviews;
        }
        return new self($count, $count > 0 ? $total / $count : null, $distribution);
    }

    /**
     * The Bayesian (confidence-weighted) average: the course's mean pulled towards a prior mean as
     * if it also had `confidence` reviews at that prior.
     *
     *     weighted = (confidence × prior + count × average) / (confidence + count)
     *
     * With few reviews the result stays near the prior; with many it approaches the course's own
     * mean. So 5.0 from one review (prior 4.0, confidence 10) is 4.09, while 4.8 from 500 is 4.78.
     * With no reviews the result is the prior; with no prior either there is nothing to weight.
     *
     * @param ?float $prior      the mean of every approved review on the platform, or null when there are none
     * @param float  $confidence how many reviews at the prior the course is assumed to start with, > 0
     */
    public function weightedAverage(?float $prior, float $confidence): ?float
    {
        if ($confidence <= 0) { throw new \InvalidArgumentException('Rating confidence must be positive.'); }
        if ($prior === null) { return $this->average; }
        return ($confidence * $prior + $this->count * ($this->average ?? 0.0)) / ($confidence + $this->count);
    }

    /**
     * How much evidence the rating rests on, from 0 (no reviews) towards 1: count / (count + confidence).
     * Half confidence is reached at `confidence` reviews.
     */
    public function confidence(float $confidence): float
    {
        if ($confidence <= 0) { throw new \InvalidArgumentException('Rating confidence must be positive.'); }
        return $this->count / ($this->count + $confidence);
    }

    /**
     * For templates: the mean to one decimal, the whole stars to fill, and each star's share.
     *
     * @return array{count:int,average:?float,average_label:string,filled_stars:int,distribution:list<array{stars:int,count:int,percent:int}>}
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'average' => $this->average,
            'average_label' => $this->average === null ? '' : number_format($this->average, 1),
            'filled_stars' => $this->average === null ? 0 : (int) round($this->average),
            'distribution' => array_map(fn(int $stars): array => [
                'stars' => $stars,
                'count' => $this->distribution[$stars],
                'percent' => $this->count > 0 ? (int) round(100 * $this->distribution[$stars] / $this->count) : 0,
            ], [5, 4, 3, 2, 1]),
        ];
    }
}
