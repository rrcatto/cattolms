<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;

/**
 * A person's course favourites. The favourites table says whether someone favourites a course
 * now; the analytics events record when that changed. Both, and the audit entry, are written in one
 * transaction, and only when the state actually changes.
 */
final class CourseFavouriteService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AdministrationRepository $favourites,
        private readonly AuditRepository $audit,
        private readonly AnalyticsEventRecorder $analytics
    ) {
    }

    /** @return bool whether the request changed anything */
    public function set(int $userId, int $courseId, bool $favourite, AnalyticsSource $source): bool
    {
        return $this->transactions->run(function () use ($userId, $courseId, $favourite, $source): bool {
            if (!$this->favourites->setFavourite($userId, $courseId, $favourite)) { return false; }
            $this->audit->record($userId, $favourite ? 'course.favourited' : 'course.unfavourited', ['course_id' => $courseId]);
            $this->analytics->record($favourite ? AnalyticsEventType::CourseFavouriteAdded : AnalyticsEventType::CourseFavouriteRemoved, $source, ['user_id' => $userId, 'course_id' => $courseId]);
            return true;
        });
    }
}
