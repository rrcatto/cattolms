<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Company\CompanyScope;
use PHPUnit\Framework\TestCase;

/**
 * Company scope needs a real company on both sides (owner, 2026-10-18). A private learner has no
 * company and a Site Owner course has no owning company; in PHP null === null is true, so they must
 * never be compared directly.
 */
final class CompanyScopeTest extends TestCase
{
    public function testAPrivateLearnerAndASiteOwnerCourseAreNeverTheSameCompany(): void
    {
        self::assertFalse(CompanyScope::same(null, null), 'a private learner and a Site Owner course');
        self::assertFalse(CompanyScope::same(0, 0), 'two missing companies read as 0');
        self::assertFalse(CompanyScope::same((int) null, (int) null));
        self::assertFalse(CompanyScope::same('', null));
        self::assertFalse(CompanyScope::same(null, 7), 'a private learner and a company course');
        self::assertFalse(CompanyScope::same(7, null), 'a company learner and a Site Owner course');
        self::assertFalse(CompanyScope::same(-1, -1));
        self::assertFalse(CompanyScope::same(7, 8));
        self::assertTrue(CompanyScope::same(7, 7));
        self::assertTrue(CompanyScope::same('7', 7), 'a database string and an integer');
    }
}
