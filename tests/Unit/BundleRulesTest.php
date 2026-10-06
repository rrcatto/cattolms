<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Bundle\BundleRules;
use CattoLearning\Bundle\BundleService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * When a bundle can be bought, its web address, and the informational comparison with buying its
 * courses one by one.
 */
final class BundleRulesTest extends TestCase
{
    private const NOW = '2026-10-07T12:00:00+02:00';

    public function testOnlyAPublishedBundleOnSaleWithTwoPublishedCoursesCanBeBought(): void
    {
        $courses = [['status' => 'published'], ['status' => 'published']];
        self::assertNull($this->reason([], $courses));
        foreach ([
            'This bundle is not available.' => [['status' => 'draft'], $courses],
            'This bundle is no longer sold.' => [['status' => 'retired'], $courses],
            'This bundle is not on sale yet.' => [['available_from' => '2026-10-07T12:00:01+02:00'], $courses],
            'This bundle is no longer on sale.' => [['available_until' => self::NOW], $courses],
            'This bundle is not available for purchase at the moment.' => [['offer_active' => false], $courses],
            'This bundle is not available for purchase at the moment. ' => [[], [['status' => 'published']]],
            'This bundle includes a course that is no longer available, so it cannot be bought at the moment.' => [[], [['status' => 'published'], ['status' => 'retired']]],
        ] as $message => [$overrides, $members]) {
            self::assertSame(trim($message), $this->reason($overrides, $members), $message);
        }
        self::assertNull($this->reason(['available_from' => self::NOW, 'available_until' => '2026-10-07T12:00:01+02:00'], $courses), 'The sales window starts inclusive and ends exclusive.');
    }

    public function testASlugIsMadeFromTheTitle(): void
    {
        self::assertSame('professional-office-skills-bundle', BundleRules::slug('  Professional Office Skills — Bundle! '));
        self::assertSame('cafe-basics-2026', BundleRules::slug('Café basics 2026'));
    }

    public function testTheComparisonIsInformationalAndNeverClaimsASavingItCannotShow(): void
    {
        $course = static fn(?int $price, string $currency = 'ZAR'): array => ['default_price_minor_units' => $price, 'default_currency_code' => $currency];
        $three = [$course(50000), $course(70000), $course(40000)];
        self::assertSame([160000, 40000], [BundleService::comparison($three, 120000, 'ZAR')['individual_minor'] ?? null, BundleService::comparison($three, 120000, 'ZAR')['saving_minor'] ?? null], 'R1,600 one by one, R1,200 as a bundle: save R400.');
        $dearer = BundleService::comparison($three, 170000, 'ZAR');
        self::assertNotNull($dearer);
        self::assertSame([160000, null, null], [$dearer['individual_minor'], $dearer['saving_minor'], $dearer['saving_label']], 'A bundle dearer than its courses claims no saving.');
        self::assertNull(BundleService::comparison([$course(50000), $course(null)], 60000, 'ZAR'), 'A course with no current price: no comparison at all.');
        self::assertNull(BundleService::comparison([$course(50000), $course(0)], 30000, 'ZAR'), 'A free course is not a comparable price.');
        self::assertNull(BundleService::comparison([$course(50000), $course(70000, 'USD')], 60000, 'ZAR'), 'Another currency is not comparable.');
    }

    /**
     * @param array<string,mixed> $overrides
     * @param list<array<string,mixed>> $courses
     */
    private function reason(array $overrides, array $courses): ?string
    {
        return BundleRules::unavailableReason($overrides + ['status' => 'published', 'available_from' => null, 'available_until' => null, 'offer_active' => true, 'price_minor_units' => 120000], $courses, new DateTimeImmutable(self::NOW));
    }
}
