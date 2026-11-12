<?php

declare(strict_types=1);

namespace CattoLearning\Bundle;

use CattoLearning\Course\CourseCode;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When a bundle can be bought. One rule for the catalogue, the cart and order placement, so a forged
 * request can never buy what the catalogue would not sell:
 *
 * - published (a draft is not shown; a retired bundle is no longer sold);
 * - inside its sales window, from available_from (inclusive) until available_until (exclusive);
 * - an offer that is on sale (published but off sale is "temporarily unavailable");
 * - at least two courses, every one of them published.
 *
 * A published bundle whose sale has not started but would otherwise be sold stays in the catalogue, saying when it goes
 * on sale (owner, 2026-11-06: "If I set a future date for start, do not remove the bundle from the catalogue - display
 * it with a start date ... show me WHEN the bundle will be available to purchase"): startsLater() names that moment,
 * and the refusal before it says it too.
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
        if (($bundle['available_from'] ?? null) !== null && $now < new DateTimeImmutable((string) $bundle['available_from'])) {
            return 'This bundle can be bought from ' . self::saleDate((string) $bundle['available_from']) . '.';
        }
        if (($bundle['available_until'] ?? null) !== null && $now >= new DateTimeImmutable((string) $bundle['available_until'])) return 'This bundle is no longer on sale.';
        if (!(bool) ($bundle['offer_active'] ?? false) || (int) ($bundle['price_minor_units'] ?? 0) < 1) return 'This bundle is not available for purchase at the moment.';
        if (count($courses) < self::MINIMUM_COURSES) return 'This bundle is not available for purchase at the moment.';
        foreach ($courses as $course) {
            if ((string) ($course['status'] ?? '') !== 'published') return 'This bundle includes a course that is no longer available, so it cannot be bought at the moment.';
        }
        return null;
    }

    /**
     * When a published bundle goes on sale, if its start is all that keeps it from being bought now; null otherwise (it
     * can be bought, or something else stops it, or its sale has ended).
     *
     * @param array<string,mixed> $bundle
     * @param list<array<string,mixed>> $courses
     */
    public static function startsLater(array $bundle, array $courses, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (($bundle['available_from'] ?? null) === null) return null;
        $start = new DateTimeImmutable((string) $bundle['available_from']);
        if ($now >= $start) return null;
        // Everything else as it will be at its start: sold then, so shown now.
        return self::unavailableReason(['available_from' => null] + $bundle, $courses, $start) === null ? $start : null;
    }

    /**
     * A moment of sale in words, in the site's time zone: "Monday 16 November 2026", with the time when it is not midnight
     * ("Monday 16 November 2026 at 08:00"), or the short form for a card or a note ("16 Nov 2026").
     */
    public static function saleDate(string|DateTimeImmutable $at, bool $short = false): string
    {
        $local = ($at instanceof DateTimeImmutable ? $at : new DateTimeImmutable($at))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        if ($short) return $local->format('j M Y');
        return $local->format('l j F Y') . ($local->format('H:i') === '00:00' ? '' : ' at ' . $local->format('H:i'));
    }

    /** A URL slug from a title: lower-case words joined by hyphens. */
    public static function slug(string $text): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT', $text) : $text;
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii !== '' ? $ascii : $text)), '-');
        return mb_substr($slug, 0, 180);
    }

    /** The prefix of a suggested code when nothing better is known: a bundle with no courses yet. */
    public const CODE_PREFIX = 'BDL';

    /**
     * A code to offer for a bundle with this title (owner, 2026-11-06: "Each bundle needs its own code, like a course
     * code"): its prefix (owner, 2026-11-12: the one its courses share, BundleService; BDL without them) and the initials of
     * its main words, up to six, then -2, -3 and on while taken. $taken says whether a code is a course's or another bundle's.
     *
     * @param callable(string):bool $taken
     */
    public static function suggestCode(string $title, callable $taken, string $prefix = self::CODE_PREFIX): string
    {
        $initials = '';
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $title) ?: [] as $word) {
            if ($word === '' || in_array(mb_strtolower($word), ['a', 'an', 'and', 'for', 'in', 'of', 'on', 'or', 'the', 'to', 'with', 'your', 'all', 'both'], true)) continue;
            $letter = strtoupper((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_substr($word, 0, 1)));
            if (preg_match('/^[A-Z0-9]$/', $letter) === 1) $initials .= $letter;
            if (strlen($initials) === 6) break;
        }
        $prefix = preg_match('/^[A-Z0-9]{1,12}$/', $prefix) === 1 ? $prefix : self::CODE_PREFIX;
        $stem = $prefix . '-' . ($initials !== '' ? $initials : 'BUNDLE');
        for ($n = 1; $n < 1000; $n++) {
            $code = $n === 1 ? $stem : $stem . '-' . $n;
            if (strlen($code) <= CourseCode::MAXIMUM && !$taken($code)) return $code;
        }
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
