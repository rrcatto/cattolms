<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use RuntimeException;

/** The person may not review this course: they have never held a genuine entitlement to it. */
final class CourseReviewNotAllowedException extends RuntimeException
{
}
