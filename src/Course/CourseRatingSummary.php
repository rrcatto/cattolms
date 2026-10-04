<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/**
 * A course's rating, from approved reviews only: how many there are, their mean and how they are
 * spread across one to five stars. This is the input a later popularity calculation consumes; it
 * deliberately carries the count and distribution as well as the mean, so that a confidence-aware
 * score can tell 5.0 from one review apart from 4.8 from five hundred. No weighting is applied here.
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
