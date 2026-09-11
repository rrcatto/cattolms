<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class CreditConsumptionRegressionTest extends TestCase
{
    /**
     * A credit consumed by starting a course stays consumed, whatever happens to access afterwards.
     *
     * The scenario used to begin by resetting the learner's progress, because that was the harshest
     * thing an administrator could do to an enrolment. Progress reset was removed in v0.6 - it
     * deleted issued certificates along with the results behind them, and nothing in the interface
     * reached it - so the sequence now starts at the access changes, which are what remain.
     */
    public function testConsumedCreditCannotBecomeReturnableAfterAccessChanges(): void
    {
        $container = CliBootstrap::boot()['container'];
        /** @var Database $db */
        $db = $container->get(Database::class);
        /** @var CourseRepository $courses */
        $courses = $container->get(CourseRepository::class);
        /** @var AdministrationRepository $administration */
        $administration = $container->get(AdministrationRepository::class);

        $suffix = strtolower(bin2hex(random_bytes(5)));
        $adminId = $learnerId = $companyId = $courseId = $enrolmentId = $creditId = $allocationId = 0;

        try {
            $adminId = (int) $db->fetchAllAssociative(
                'INSERT INTO users (public_id,display_name,status) VALUES (:id,:name,\'active\') RETURNING id',
                ['id' => Uuid::v4(), 'name' => 'QA admin ' . $suffix]
            )[0]['id'];
            $learnerId = (int) $db->fetchAllAssociative(
                'INSERT INTO users (public_id,display_name,status) VALUES (:id,:name,\'active\') RETURNING id',
                ['id' => Uuid::v4(), 'name' => 'QA learner ' . $suffix]
            )[0]['id'];
            $companyId = (int) $db->fetchAllAssociative(
                "INSERT INTO companies (public_id,name,domain,status,is_system,created_by_user_id)
                 VALUES (:public_id,:name,:domain,'active',FALSE,:user_id) RETURNING id",
                ['public_id' => Uuid::v4(), 'name' => 'QA Company ' . $suffix, 'domain' => 'qa-' . $suffix . '.example', 'user_id' => $adminId]
            )[0]['id'];
            $courseId = (int) $db->fetchAllAssociative(
                "INSERT INTO courses
                    (public_id,slug,title,owner_user_id,owner_company_id,created_by_user_id,updated_by_user_id)
                 VALUES (:public_id,:slug,:title,:owner_user_id,:owner_company_id,:created_by,:updated_by) RETURNING id",
                [
                    'public_id' => Uuid::v4(), 'slug' => 'qa-credit-' . $suffix, 'title' => 'QA Credit Course ' . $suffix,
                    'owner_user_id' => $adminId, 'owner_company_id' => $companyId, 'created_by' => $adminId, 'updated_by' => $adminId,
                ]
            )[0]['id'];
            $enrolmentId = (int) $db->fetchAllAssociative(
                "INSERT INTO course_enrolments
                    (public_id,user_id,course_id,status,access_period_seconds,assigned_by_user_id,is_preview)
                 VALUES (:public_id,:user_id,:course_id,'assigned',2592000,:actor,FALSE) RETURNING id",
                ['public_id' => Uuid::v4(), 'user_id' => $learnerId, 'course_id' => $courseId, 'actor' => $adminId]
            )[0]['id'];
            $creditId = (int) $db->fetchAllAssociative(
                "INSERT INTO course_credits
                    (public_id,company_id,course_id,access_period_seconds,quantity,created_by_user_id)
                 VALUES (:public_id,:company_id,:course_id,2592000,1,:actor) RETURNING id",
                ['public_id' => Uuid::v4(), 'company_id' => $companyId, 'course_id' => $courseId, 'actor' => $adminId]
            )[0]['id'];
            $allocationId = (int) $db->fetchAllAssociative(
                "INSERT INTO course_credit_allocations
                    (credit_id,user_id,enrolment_id,status,assigned_by_user_id)
                 VALUES (:credit_id,:user_id,:enrolment_id,'assigned',:actor) RETURNING id",
                ['credit_id' => $creditId, 'user_id' => $learnerId, 'enrolment_id' => $enrolmentId, 'actor' => $adminId]
            )[0]['id'];

            $courses->startEnrolment($enrolmentId, $learnerId);
            $administration->removeEnrolmentAccess($enrolmentId, $adminId, 'QA regression test');
            $administration->restoreEnrolmentAccess($enrolmentId, $adminId);
            $administration->removeEnrolmentAccess($enrolmentId, $adminId, 'QA regression test again');

            $allocation = $db->fetchAllAssociative('SELECT status,consumed_at,returned_at FROM course_credit_allocations WHERE id=:id', ['id' => $allocationId])[0];
            $enrolment = $db->fetchAllAssociative('SELECT status,started_at,expires_at FROM course_enrolments WHERE id=:id', ['id' => $enrolmentId])[0];

            self::assertSame('consumed', (string) $allocation['status']);
            self::assertNotSame('', trim((string) ($allocation['consumed_at'] ?? '')));
            self::assertSame('', trim((string) ($allocation['returned_at'] ?? '')));
            self::assertSame('cancelled', (string) $enrolment['status']);
            self::assertNotSame('', trim((string) ($enrolment['started_at'] ?? '')));
            self::assertNotSame('', trim((string) ($enrolment['expires_at'] ?? '')));
        } finally {
            if ($allocationId > 0) $db->executeStatement('DELETE FROM course_credit_allocations WHERE id=:id', ['id' => $allocationId]);
            if ($creditId > 0) $db->executeStatement('DELETE FROM course_credits WHERE id=:id', ['id' => $creditId]);
            if ($enrolmentId > 0) $db->executeStatement('DELETE FROM course_enrolments WHERE id=:id', ['id' => $enrolmentId]);
            if ($courseId > 0) $db->executeStatement('DELETE FROM courses WHERE id=:id', ['id' => $courseId]);
            if ($companyId > 0) $db->executeStatement('DELETE FROM companies WHERE id=:id', ['id' => $companyId]);
            if ($learnerId > 0) $db->executeStatement('DELETE FROM users WHERE id=:id', ['id' => $learnerId]);
            if ($adminId > 0) $db->executeStatement('DELETE FROM users WHERE id=:id', ['id' => $adminId]);
        }
    }
}