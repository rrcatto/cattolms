<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/**
 * A course's grading scale as ranges. Grade bands store only where each band starts; a band runs up
 * to just below the start of the band above it, and the top band ends at 100%. The same ranges feed
 * the Grades tab, the public course page and the [grading-scale] placeholder in authored content.
 */
final class GradeScale
{
    public const PLACEHOLDER = '[grading-scale]';

    /**
     * @param list<array<string,mixed>> $bands
     * @return list<array<string,mixed>> bands ordered highest first, with maximum_percentage, minimum_label, maximum_label and range_label
     */
    public static function ranges(array $bands): array
    {
        usort($bands, static fn(array $a, array $b): int => (float) $b['minimum_percentage'] <=> (float) $a['minimum_percentage']);
        $above = null;
        foreach ($bands as &$band) {
            $minimum = (float) $band['minimum_percentage'];
            if ($above === null) {
                $maximum = 100.0;
            } else {
                $maximum = self::whole($above) && self::whole($minimum) ? $above - 1 : round($above - 0.01, 2);
            }
            $band['maximum_percentage'] = max($minimum, $maximum);
            $band['minimum_label'] = self::number($minimum);
            $band['maximum_label'] = self::number($band['maximum_percentage']);
            $band['range_label'] = $band['minimum_label'] . '–' . $band['maximum_label'] . '%';
            $above = $minimum;
        }
        unset($band);
        return $bands;
    }

    /**
     * Replace every [grading-scale] placeholder with the course's current scale.
     * @param list<array<string,mixed>> $bands
     */
    public static function resolve(string $html, array $bands): string
    {
        if (!str_contains($html, self::PLACEHOLDER)) {
            return $html;
        }
        // scale-list is the class the imported course stylesheets already style; cl-grading-scale is the platform hook.
        $list = '<ul class="cl-grading-scale scale-list">';
        foreach (self::ranges($bands) as $band) {
            $list .= '<li><span>' . self::escape((string) $band['grade_label']) . '</span><b>' . self::escape((string) $band['range_label']) . '</b></li>';
        }
        return str_replace(self::PLACEHOLDER, $list . '</ul>', $html);
    }

    public static function number(float $value): string
    {
        return self::whole($value) ? (string) (int) round($value) : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function whole(float $value): bool
    {
        return abs($value - round($value)) < 0.00001;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
