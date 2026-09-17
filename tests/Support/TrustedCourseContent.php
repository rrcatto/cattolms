<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

final class TrustedCourseContent
{
    public const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 60" aria-label="Gradient diagram"><defs><linearGradient id="lessonGradient"><stop offset="0%" stop-color="red"></stop><stop offset="100%" stop-color="blue"></stop></linearGradient><clipPath id="lessonClip"><rect width="120" height="60"></rect></clipPath></defs><rect width="120" height="60" fill="url(#lessonGradient)" clip-path="url(#lessonClip)"></rect><foreignObject width="30" height="20"><div xmlns="http://www.w3.org/1999/xhtml">Label</div></foreignObject></svg>';
}
