<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use RuntimeException;

/** The learner may have the file, but its Resource record or stored file is missing. */
final class ResourceUnavailable extends RuntimeException
{
}
