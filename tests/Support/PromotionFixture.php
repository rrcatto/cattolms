<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Commerce\Application\PromotionService;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Infrastructure\PromotionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * Promotions for commerce tests: a PromotionService bound to a test's own connection and clock, and
 * promotions written directly (the ADMIN editor's validation has tests of its own).
 */
final class PromotionFixture
{
    public static function service(Database $db, ClockInterface $clock): PromotionService
    {
        return new PromotionService(new PromotionRepository($db), new CommerceRepository($db), new TransactionManager($db), $clock,
            new AnalyticsEventRecorder(new AnalyticsEventRepository($db), $clock));
    }

    /**
     * An active promotion: 10% off all courses unless the overrides say otherwise.
     *
     * @param array<string,mixed> $overrides promotions columns
     * @param list<int> $courseIds selected courses: course_scope becomes selected unless overridden
     * @param list<int> $bundleIds selected bundles: bundle_scope becomes selected unless overridden
     */
    public static function create(Database $db, int $actorId, array $overrides = [], array $courseIds = [], array $bundleIds = []): int
    {
        $fields = $overrides + [
            'code' => 'TEST' . strtoupper(bin2hex(random_bytes(4))),
            'name' => 'Test promotion',
            'description' => null,
            'discount_type' => 'percentage',
            'discount_value' => 1000,
            'currency' => null,
            'starts_at' => null,
            'ends_at' => null,
            'active' => true,
            'minimum_order_minor' => null,
            'maximum_total_uses' => null,
            'maximum_uses_per_customer' => null,
            'course_scope' => $courseIds === [] ? ($bundleIds === [] ? 'all' : 'none') : 'selected',
            'bundle_scope' => $bundleIds === [] ? 'none' : 'selected',
        ];
        return (new PromotionRepository($db))->create($fields, $courseIds, $actorId, '2026-09-01T00:00:00+02:00', $bundleIds);
    }
}
