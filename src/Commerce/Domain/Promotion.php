<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

use CattoLearning\Support\Money;
use DateTimeImmutable;

/**
 * A promotion as it is defined now: the current, editable definition a promo code refers to. An
 * order never reads this after placement; it keeps its own snapshot of what was applied.
 *
 * discountValue is basis points for a percentage (1000 = 10%, 10000 = 100%) and minor units of
 * currency for a fixed amount. The validity window is half-open: a promotion is valid from startsAt
 * inclusive until endsAt exclusive, so a promotion ending at midnight is no longer valid at midnight.
 *
 * What it discounts is two scopes, one per product type: courses and bundles, each none, all or
 * selected. A line is eligible by its own product type and id only: a bundle is never eligible
 * because it contains a course the promotion selects.
 */
final readonly class Promotion
{
    public const PERCENTAGE = 'percentage';
    public const FIXED_AMOUNT = 'fixed_amount';
    public const SCOPE_NONE = 'none';
    public const SCOPE_ALL = 'all';
    public const SCOPE_SELECTED = 'selected';
    public const SCOPES = [self::SCOPE_NONE, self::SCOPE_ALL, self::SCOPE_SELECTED];
    public const PRODUCT_COURSE = 'course';
    public const PRODUCT_BUNDLE = 'bundle';
    /** The canonical form of a code: 3 to 40 upper-case letters, digits, hyphens or underscores. */
    public const CODE_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{2,39}$/D';

    /**
     * @param list<int> $courseIds the selected courses; empty unless courseScope is selected
     * @param list<int> $bundleIds the selected bundles; empty unless bundleScope is selected
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $description,
        public string $discountType,
        public int $discountValue,
        public ?string $currency,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $active,
        public ?int $minimumOrderMinor,
        public ?int $maximumTotalUses,
        public ?int $maximumUsesPerCustomer,
        public string $courseScope,
        public string $bundleScope,
        public array $courseIds,
        public array $bundleIds,
    ) {}

    /**
     * @param array<string,mixed> $row a promotions row
     * @param list<int> $courseIds
     * @param list<int> $bundleIds
     */
    public static function fromRow(array $row, array $courseIds = [], array $bundleIds = []): self
    {
        $time = static fn(mixed $value): ?DateTimeImmutable => $value === null ? null : new DateTimeImmutable((string) $value);
        $int = static fn(mixed $value): ?int => $value === null ? null : (int) $value;
        return new self(
            (int) $row['id'], (string) $row['code'], (string) $row['name'], $row['description'] === null ? null : (string) $row['description'],
            (string) $row['discount_type'], (int) $row['discount_value'], $row['currency'] === null ? null : (string) $row['currency'],
            $time($row['starts_at']), $time($row['ends_at']), (bool) $row['active'],
            $int($row['minimum_order_minor']), $int($row['maximum_total_uses']), $int($row['maximum_uses_per_customer']),
            (string) $row['course_scope'], (string) $row['bundle_scope'], array_map('intval', $courseIds), array_map('intval', $bundleIds),
        );
    }

    /**
     * The canonical form of a code as someone typed it: surrounding space removed and upper-cased,
     * so codes are case-insensitive. The result may still be invalid; see isValidCode().
     */
    public static function normaliseCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function isValidCode(string $canonical): bool
    {
        return preg_match(self::CODE_PATTERN, $canonical) === 1;
    }

    /** Whether a line of this product type and id is eligible. */
    public function appliesTo(string $productType, int $productId): bool
    {
        [$scope, $selected] = match ($productType) {
            self::PRODUCT_COURSE => [$this->courseScope, $this->courseIds],
            self::PRODUCT_BUNDLE => [$this->bundleScope, $this->bundleIds],
            default => [self::SCOPE_NONE, []],
        };
        return $scope === self::SCOPE_ALL || ($scope === self::SCOPE_SELECTED && in_array($productId, $selected, true));
    }

    /** "All courses", "3 courses and all bundles", "All courses and bundles". */
    public function scopeLabel(): string
    {
        return self::describeScope($this->courseScope, $this->bundleScope, count($this->courseIds), count($this->bundleIds));
    }

    public static function describeScope(string $courseScope, string $bundleScope, int $courseCount, int $bundleCount): string
    {
        if ($courseScope === self::SCOPE_ALL && $bundleScope === self::SCOPE_ALL) return 'All courses and bundles';
        $parts = [];
        foreach ([[$courseScope, $courseCount, 'course'], [$bundleScope, $bundleCount, 'bundle']] as [$scope, $count, $noun]) {
            if ($scope === self::SCOPE_ALL) $parts[] = 'all ' . $noun . 's';
            elseif ($scope === self::SCOPE_SELECTED) $parts[] = $count . ' ' . $noun . ($count === 1 ? '' : 's');
        }
        return ucfirst(implode(' and ', $parts));
    }

    /** "10% off", "12.5% off" or "R100.00 off". */
    public function discountLabel(?string $locale = null): string
    {
        return self::label($this->discountType, $this->discountValue, $this->currency, $locale);
    }

    public static function label(string $type, int $value, ?string $currency, ?string $locale = null): string
    {
        return $type === self::PERCENTAGE
            ? self::percentText($value) . '% off'
            : Money::strictMinorUnits($value, (string) $currency)->format($locale) . ' off';
    }

    /** Basis points as a percentage without trailing zeros: 1000 is "10", 1250 is "12.5", 1 is "0.01". */
    public static function percentText(int $basisPoints): string
    {
        $text = intdiv($basisPoints, 100) . '.' . str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
        return rtrim(rtrim($text, '0'), '.');
    }

    /** Whether the window has opened yet, is open, or has closed: 'scheduled', 'open' or 'ended'. */
    public function window(DateTimeImmutable $now): string
    {
        if ($this->startsAt !== null && $now < $this->startsAt) return 'scheduled';
        if ($this->endsAt !== null && $now >= $this->endsAt) return 'ended';
        return 'open';
    }

    /** For ADMIN lists: 'inactive', 'scheduled', 'active' or 'ended'. Usage limits are not considered. */
    public function status(DateTimeImmutable $now): string
    {
        $window = $this->window($now);
        if ($window === 'ended') return 'ended';
        if (!$this->active) return 'inactive';
        return $window === 'scheduled' ? 'scheduled' : 'active';
    }
}
