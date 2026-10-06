<?php

declare(strict_types=1);

namespace CattoLearning\Support;

use InvalidArgumentException;

/**
 * An access period as an offer states it: a number of days, weeks, months (30 days) or years (365
 * days), held as seconds. Course price variants and bundle offers both describe access this way.
 */
final class AccessPeriod
{
    public const UNITS = ['days' => 86400, 'weeks' => 604800, 'months' => 2592000, 'years' => 31536000];
    private const LIMITS = ['days' => 3650, 'weeks' => 520, 'months' => 120, 'years' => 10];

    /** Seconds from a typed value and unit. */
    public static function fromInput(mixed $value, mixed $unit): int
    {
        $count = max(1, (int) $value);
        $unit = trim((string) $unit);
        if (!isset(self::UNITS[$unit])) {
            throw new InvalidArgumentException('Choose a valid access-period unit.');
        }
        if ($count > self::LIMITS[$unit]) {
            throw new InvalidArgumentException('The access period is unreasonably long.');
        }
        return $count * self::UNITS[$unit];
    }

    /**
     * The largest whole unit that states the period exactly.
     *
     * @return array{0:int,1:string}
     */
    public static function decompose(int $seconds): array
    {
        foreach (['years', 'months', 'weeks'] as $unit) {
            if ($seconds > 0 && $seconds % self::UNITS[$unit] === 0) {
                return [intdiv($seconds, self::UNITS[$unit]), $unit];
            }
        }
        return [max(1, (int) round($seconds / 86400)), 'days'];
    }

    /** "1 year", "6 months", "45 days". */
    public static function label(int $seconds): string
    {
        [$value, $unit] = self::decompose($seconds);
        return $value . ' ' . ($value === 1 ? rtrim($unit, 's') : $unit);
    }
}
