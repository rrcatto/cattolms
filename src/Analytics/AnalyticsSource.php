<?php

declare(strict_types=1);

namespace CattoLearning\Analytics;

/**
 * Where an analytics event came from. A controlled vocabulary, so reports can compare origins
 * without guessing at free-form strings.
 */
enum AnalyticsSource: string
{
    case Catalogue = 'catalogue';
    case CourseDetail = 'course_detail';
    /** A course's marketing landing page, /landing/{slug}. */
    case LandingPage = 'landing_page';
    case PublicPreview = 'public_preview';
    case LearnerReader = 'learner_reader';
    case Account = 'account';
    case Checkout = 'checkout';
    /** The learner's rate-and-review form. */
    case CourseReview = 'course_review';
    case Admin = 'admin';
}
