<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\Bundle\BundleRepository;
use CattoLearning\Infrastructure\Persistence\Database;

/**
 * Bundles for tests, written directly (the ADMIN service's rules have tests of their own): a
 * published bundle of the given courses with an offer on sale.
 */
final class BundleFixture
{
    /**
     * @param list<int> $courseIds in bundle order
     * @param array<string,mixed> $overrides bundles columns (status, available_from, available_until, title, slug)
     * @return array{id:int,offer:int,slug:string,title:string}
     */
    public static function create(Database $db, int $actorId, array $courseIds, int $priceMinor = 120000, array $overrides = [], int $periodSeconds = 31536000, bool $onSale = true): array
    {
        $repository = new BundleRepository($db);
        $slug = (string) ($overrides['slug'] ?? 'bundle-' . bin2hex(random_bytes(5)));
        $title = (string) ($overrides['title'] ?? 'Test bundle ' . substr($slug, -6));
        $now = '2026-09-01T00:00:00+02:00';
        $id = $repository->create(['slug' => $slug, 'title' => $title, 'short_description' => 'Several courses together.', 'description_html' => '<p>A bundle.</p>', 'cover_svg' => '',
            'available_from' => $overrides['available_from'] ?? null, 'available_until' => $overrides['available_until'] ?? null], $actorId, $now);
        foreach ($courseIds as $courseId) $repository->addCourse($id, $courseId);
        $repository->saveOffer($id, $priceMinor, 'ZAR', $periodSeconds, $onSale, $actorId, $now);
        $repository->setStatus($id, (string) ($overrides['status'] ?? 'published'), $actorId, $now);
        return ['id' => $id, 'offer' => (int) $db->fetchOne('SELECT id FROM bundle_offers WHERE bundle_id=:id', ['id' => $id]), 'slug' => $slug, 'title' => $title];
    }
}
