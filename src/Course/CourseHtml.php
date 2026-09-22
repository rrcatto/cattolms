<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/** Owner-authored course content is trusted; this class only normalises course structure. */
final class CourseHtml
{
    public function preserve(string $html): string
    {
        return trim($html);
    }

    /** Preserve the authored Learning Outcomes block, including its heading and styling hooks. */
    public function learningOutcomes(string $html): string
    {
        return $this->preserve($html);
    }
}
