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
            // When it can be bought, never "not on sale yet" (owner, 2026-11-06: "show me WHEN the bundle will be available to purchase").
            'This bundle can be bought from Wednesday 7 October 2026 at 12:00.' => [['available_from' => '2026-10-07T12:00:01+02:00'], $courses],
            'This bundle is no longer on sale.' => [['available_until' => self::NOW], $courses],
            'This bundle is not available for purchase at the moment.' => [['offer_active' => false], $courses],
            'This bundle is not available for purchase at the moment. ' => [[], [['status' => 'published']]],
            'This bundle includes a course that is no longer available, so it cannot be bought at the moment.' => [[], [['status' => 'published'], ['status' => 'retired']]],
        ] as $message => [$overrides, $members]) {
            self::assertSame(trim($message), $this->reason($overrides, $members), $message);
        }
        self::assertNull($this->reason(['available_from' => self::NOW, 'available_until' => '2026-10-07T12:00:01+02:00'], $courses), 'The sales window starts inclusive and ends exclusive.');
    }

    public function testABundleWhoseSaleStartsLaterIsShownWithWhen(): void
    {
        $courses = [['status' => 'published'], ['status' => 'published']];
        $later = static fn(array $overrides, array $members = []): ?DateTimeImmutable => BundleRules::startsLater($overrides + ['status' => 'published', 'available_from' => '2026-12-01T00:00:00+02:00', 'available_until' => null, 'offer_active' => true, 'price_minor_units' => 120000], $members ?: $courses, new DateTimeImmutable(self::NOW));
        self::assertSame('2026-12-01T00:00:00+02:00', $later([])?->format(DATE_ATOM), 'Published, priced, on sale, its courses published: only its start keeps it from being bought.');
        self::assertNull($later(['available_from' => self::NOW]), 'A sale that has started is not later.');
        self::assertNull($later(['available_from' => null]), 'No start: nothing to wait for.');
        foreach ([['status' => 'draft'], ['status' => 'retired'], ['offer_active' => false], ['price_minor_units' => 0], ['available_until' => '2026-11-30T00:00:00+02:00']] as $other) {
            self::assertNull($later($other), 'Something else stops it, so it is not shown: ' . json_encode($other));
        }
        self::assertNull($later([], [['status' => 'published'], ['status' => 'draft']]), 'A course that is not published keeps it off sale.');

        $zone = date_default_timezone_get();
        date_default_timezone_set('Africa/Johannesburg');
        try {
            self::assertSame('Tuesday 1 December 2026', BundleRules::saleDate('2026-12-01T00:00:00+02:00'), 'Midnight: the day alone.');
            self::assertSame('Monday 16 November 2026 at 08:00', BundleRules::saleDate('2026-11-16T06:00:00+00:00'), 'Another time: with the time, in the site\'s time zone.');
            self::assertSame('16 Nov 2026', BundleRules::saleDate('2026-11-16T08:00:00+02:00', true), 'The short form for a card or a note.');
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testABundleCodeIsSuggestedFromItsTitleAndNeverTaken(): void
    {
        self::assertSame('BDL-POS', BundleRules::suggestCode('Professional Office Skills', static fn(string $code): bool => false));
        self::assertSame('BDL-POS-2', BundleRules::suggestCode('Professional Office Skills', static fn(string $code): bool => $code === 'BDL-POS'), 'Taken: the next free one.');
        self::assertSame('BDL-GDP', BundleRules::suggestCode('Garden Design: All the Parts', static fn(string $code): bool => false), 'Small words are left out.');
        self::assertSame('BDL-BUNDLE', BundleRules::suggestCode('—', static fn(string $code): bool => false));
        // The prefix its courses share (owner, 2026-11-12), which places it in that prefix's band.
        self::assertSame('FIN-BB', BundleRules::suggestCode('Baobab Books', static fn(string $code): bool => false, 'FIN'));
        self::assertSame('BDL-BB', BundleRules::suggestCode('Baobab Books', static fn(string $code): bool => false, 'not a prefix'), 'Anything but a prefix: BDL.');
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
