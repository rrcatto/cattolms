<?php

declare(strict_types=1);

namespace CattoLearning\Analytics;

/**
 * Where an analytics event came from. A controlled vocabulary, so reports can compare origins
 * without guessing at free-form strings. landing_page is added with the landing pages.
 */
enum AnalyticsSource: string
{
    case Catalogue = 'catalogue';
    case CourseDetail = 'course_detail';
    case PublicPreview = 'public_preview';
    case LearnerReader = 'learner_reader';
    case Account = 'account';
    case Checkout = 'checkout';
    case Admin = 'admin';
}
