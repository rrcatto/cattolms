<?php

declare(strict_types=1);

namespace CattoLearning\Bundle;

use DateTimeImmutable;

/**
 * When a bundle can be bought. One rule for the catalogue, the cart and order placement, so a forged
 * request can never buy what the catalogue would not sell:
 *
 * - published (a draft is not shown; a retired bundle is no longer sold);
 * - inside its sales window, from available_from (inclusive) until available_until (exclusive);
 * - an offer that is on sale (published but off sale is "temporarily unavailable");
 * - at least two courses, every one of them published.
 */
final class BundleRules
{
    public const MINIMUM_COURSES = 2;
    public const STATUSES = ['draft', 'published', 'retired'];

    /**
     * Why the bundle cannot be bought now, in words for the purchaser; null when it can.
     *
     * @param array<string,mixed> $bundle a bundle with its offer: status, available_from, available_until, offer_active, price_minor_units
     * @param list<array<string,mixed>> $courses its courses, each with a status
     */
    public static function unavailableReason(array $bundle, array $courses, DateTimeImmutable $now): ?string
    {
        $status = (string) ($bundle['status'] ?? '');
        if ($status === 'retired') return 'This bundle is no longer sold.';
        if ($status !== 'published') return 'This bundle is not available.';
        if (($bundle['available_from'] ?? null) !== null && $now < new DateTimeImmutable((string) $bundle['available_from'])) return 'This bundle is not on sale yet.';
        if (($bundle['available_until'] ?? null) !== null && $now >= new DateTimeImmutable((string) $bundle['available_until'])) return 'This bundle is no longer on sale.';
        if (!(bool) ($bundle['offer_active'] ?? false) || (int) ($bundle['price_minor_units'] ?? 0) < 1) return 'This bundle is not available for purchase at the moment.';
        if (count($courses) < self::MINIMUM_COURSES) return 'This bundle is not available for purchase at the moment.';
        foreach ($courses as $course) {
            if ((string) ($course['status'] ?? '') !== 'published') return 'This bundle includes a course that is no longer available, so it cannot be bought at the moment.';
        }
        return null;
    }

    /** A URL slug from a title: lower-case words joined by hyphens. */
    public static function slug(string $text): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $text) : $text;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== '' ? $ascii : $text)), '-');
        return mb_substr($slug, 0, 180);
    }
}
