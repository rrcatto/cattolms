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

    /**
     * Marks each course card with whether this person favourites it, for drawing its star. With
     * nobody signed in every card is left as it is.
     *
     * @param list<array<string,mixed>> $courses
     * @return list<array<string,mixed>>
     */
    public function markFavourites(?int $userId, array $courses): array
    {
        if ($userId === null || $courses === []) {
            return $courses;
        }
        $favourites = array_flip($this->favourites->favouriteCourseIds($userId, array_map(static fn(array $course): int => (int) $course['id'], $courses)));
        foreach ($courses as &$course) {
            $course['is_favourite'] = isset($favourites[(int) $course['id']]);
        }
        unset($course);
        return $courses;
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
