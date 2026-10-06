<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Domain\Promotion;
use CattoLearning\Commerce\Infrastructure\PromotionRepository;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * ADMIN management of promotions. A promotion is current, editable data: a change applies to
 * checkouts from now on and never to an order already placed, which keeps its own snapshot. A
 * promotion that any order was placed with is kept for that history and can only be deactivated;
 * one nobody has used can be deleted.
 *
 * Amounts are in the platform currency, the only currency checkout accepts. Dates and times are
 * entered and shown in the platform time zone; a promotion is valid from its start (inclusive)
 * until its end (exclusive).
 */
final class PromotionAdministrationService
{
    private const NAME_LIMIT = 120;
    private const DESCRIPTION_LIMIT = 1000;
    private const MAX_USES = 1000000;

    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly TransactionManager $transactions,
        private readonly AuditRepository $audit,
        private readonly ClockInterface $clock,
    ) {}

    /** @param array<string,mixed> $input the editor's fields */
    public function create(CurrentUser $actor, array $input): int
    {
        OrderService::requireCapability($actor, 'PLATFORM.PROMOTION.MANAGE');
        [$fields, $courses, $bundles] = $this->validate($input);
        return $this->transactions->run(function () use ($actor, $fields, $courses, $bundles): int {
            $this->requireFreeCode((string) $fields['code'], null);
            try {
                $id = $this->promotions->create($fields, $courses, $actor->id, $this->now()->format(DATE_ATOM), $bundles);
            } catch (UniqueConstraintViolationException) {
                throw self::codeTaken((string) $fields['code']);
            }
            $this->audit->record($actor->id, 'promotion.created', ['promotion_id' => $id, 'code' => $fields['code']]);
            return $id;
        });
    }

    /** @param array<string,mixed> $input the editor's fields */
    public function update(CurrentUser $actor, int $id, array $input): void
    {
        OrderService::requireCapability($actor, 'PLATFORM.PROMOTION.MANAGE');
        [$fields, $courses, $bundles] = $this->validate($input);
        $this->transactions->run(function () use ($actor, $id, $fields, $courses, $bundles): void {
            // Locked so a placement evaluating this promotion finishes before the change, or sees it whole.
            $before = $this->promotions->find($id, true) ?? throw new RuntimeException('This promotion no longer exists.');
            $this->requireFreeCode((string) $fields['code'], $id);
            try {
                $this->promotions->update($id, $fields, $courses, $actor->id, $this->now()->format(DATE_ATOM), $bundles);
            } catch (UniqueConstraintViolationException) {
                throw self::codeTaken((string) $fields['code']);
            }
            $after = $this->promotions->find($id) ?? throw new RuntimeException('This promotion no longer exists.');
            $changed = self::changes($before, $after);
            if ($changed !== []) $this->audit->record($actor->id, 'promotion.updated', ['promotion_id' => $id, 'code' => $after->code, 'changed' => $changed]);
        });
    }

    /** Returns false when the promotion was already in that state. */
    public function setActive(CurrentUser $actor, int $id, bool $active): bool
    {
        OrderService::requireCapability($actor, 'PLATFORM.PROMOTION.MANAGE');
        return $this->transactions->run(function () use ($actor, $id, $active): bool {
            $promotion = $this->promotions->find($id, true) ?? throw new RuntimeException('This promotion no longer exists.');
            if (!$this->promotions->setActive($id, $active, $actor->id, $this->now()->format(DATE_ATOM))) return false;
            $this->audit->record($actor->id, $active ? 'promotion.activated' : 'promotion.deactivated', ['promotion_id' => $id, 'code' => $promotion->code]);
            return true;
        });
    }

    /** Deletes a promotion no order was ever placed with. A used promotion is kept; deactivate it instead. */
    public function delete(CurrentUser $actor, int $id): void
    {
        OrderService::requireCapability($actor, 'PLATFORM.PROMOTION.MANAGE');
        $this->transactions->run(function () use ($actor, $id): void {
            $promotion = $this->promotions->find($id, true) ?? throw new RuntimeException('This promotion no longer exists.');
            if ($this->promotions->used($id)) throw self::usedPromotion();
            try {
                $this->promotions->delete($id);
            } catch (ForeignKeyConstraintViolationException) {
                throw self::usedPromotion();
            }
            $this->audit->record($actor->id, 'promotion.deleted', ['promotion_id' => $id, 'code' => $promotion->code]);
        });
    }

    /**
     * The editor's values for a promotion, or for a new one.
     *
     * @return array<string,mixed>
     */
    public function form(?Promotion $promotion): array
    {
        $time = static fn(?DateTimeImmutable $value): string => $value?->setTimezone(self::timezone())->format('Y-m-d\TH:i') ?? '';
        $amount = static fn(?int $minor): string => $minor === null ? '' : Money::strictMinorUnits($minor, Money::platformCurrency())->toMajorUnits();
        if ($promotion === null) {
            return ['code' => '', 'name' => '', 'description' => '', 'active' => true, 'discount_type' => Promotion::PERCENTAGE, 'discount_value' => '',
                'minimum_order' => '', 'maximum_total_uses' => '', 'maximum_uses_per_customer' => '', 'starts_at' => '', 'ends_at' => '',
                'course_scope' => Promotion::SCOPE_ALL, 'bundle_scope' => Promotion::SCOPE_NONE, 'courses' => [], 'bundles' => []];
        }
        return [
            'code' => $promotion->code, 'name' => $promotion->name, 'description' => (string) $promotion->description, 'active' => $promotion->active,
            'discount_type' => $promotion->discountType,
            'discount_value' => $promotion->discountType === Promotion::PERCENTAGE ? Promotion::percentText($promotion->discountValue) : $amount($promotion->discountValue),
            'minimum_order' => $amount($promotion->minimumOrderMinor),
            'maximum_total_uses' => (string) ($promotion->maximumTotalUses ?? ''), 'maximum_uses_per_customer' => (string) ($promotion->maximumUsesPerCustomer ?? ''),
            'starts_at' => $time($promotion->startsAt), 'ends_at' => $time($promotion->endsAt),
            'course_scope' => $promotion->courseScope, 'bundle_scope' => $promotion->bundleScope,
            'courses' => $this->promotions->selectedCourses($promotion->id), 'bundles' => $this->promotions->selectedBundles($promotion->id),
        ];
    }

    /**
     * Values a failed save is shown again with, from what was submitted.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function formFromInput(array $input): array
    {
        $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
        return [
            'code' => $text('code'), 'name' => $text('name'), 'description' => $text('description'), 'active' => ($input['active'] ?? '') === '1',
            'discount_type' => $text('discount_type') === Promotion::FIXED_AMOUNT ? Promotion::FIXED_AMOUNT : Promotion::PERCENTAGE,
            'discount_value' => $text('discount_value'), 'minimum_order' => $text('minimum_order'),
            'maximum_total_uses' => $text('maximum_total_uses'), 'maximum_uses_per_customer' => $text('maximum_uses_per_customer'),
            'starts_at' => $text('starts_at'), 'ends_at' => $text('ends_at'),
            'course_scope' => in_array($text('course_scope'), Promotion::SCOPES, true) ? $text('course_scope') : Promotion::SCOPE_ALL,
            'bundle_scope' => in_array($text('bundle_scope'), Promotion::SCOPES, true) ? $text('bundle_scope') : Promotion::SCOPE_NONE,
            'courses' => $this->promotions->coursesById(self::ids($input, 'course')),
            'bundles' => $this->promotions->bundlesById(self::ids($input, 'bundle')),
        ];
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:list<int>,2:list<int>}
     */
    public function validate(array $input): array
    {
        $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $code = Promotion::normaliseCode($text('code'));
        if (!Promotion::isValidCode($code)) throw new RuntimeException('Use 3 to 40 letters, digits, hyphens or underscores for the code, starting with a letter or digit.');
        $name = $text('name');
        if ($name === '' || mb_strlen($name) > self::NAME_LIMIT) throw new RuntimeException('Give the promotion a name of at most ' . self::NAME_LIMIT . ' characters.');
        $description = $text('description');
        if (mb_strlen($description) > self::DESCRIPTION_LIMIT) throw new RuntimeException('Keep the description to ' . self::DESCRIPTION_LIMIT . ' characters.');
        foreach ([$name, $description] as $value) {
            if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) throw new RuntimeException('Remove the control characters from the name or description.');
        }

        $type = $text('discount_type');
        if (!in_array($type, [Promotion::PERCENTAGE, Promotion::FIXED_AMOUNT], true)) throw new RuntimeException('Choose a percentage or a fixed amount off.');
        if ($type === Promotion::PERCENTAGE) {
            if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/D', $text('discount_value'), $parts) !== 1) throw new RuntimeException('Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.');
            $value = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
            if ($value < 1 || $value > 10000) throw new RuntimeException('Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.');
        } else {
            $value = self::amount($text('discount_value')) ?? 0;
            if ($value < 1) throw new RuntimeException('Enter an amount off greater than zero, such as 100 or 99.50.');
        }
        $minimum = $text('minimum_order') === '' ? null : self::amount($text('minimum_order'));
        if ($minimum !== null && $minimum < 1) throw new RuntimeException('Leave the minimum spend empty, or enter an amount greater than zero.');
        if ($text('minimum_order') !== '' && $minimum === null) throw new RuntimeException('Enter the minimum spend in rands and cents, such as 1000 or 999.99.');

        $uses = [];
        foreach (['maximum_total_uses' => 'total uses', 'maximum_uses_per_customer' => 'uses per customer'] as $key => $label) {
            $raw = $text($key);
            if ($raw === '') { $uses[$key] = null; continue; }
            if (preg_match('/^\d{1,7}$/D', $raw) !== 1 || (int) $raw < 1 || (int) $raw > self::MAX_USES) throw new RuntimeException('Leave the maximum ' . $label . ' empty for no limit, or enter a whole number from 1 to ' . number_format(self::MAX_USES) . '.');
            $uses[$key] = (int) $raw;
        }

        $starts = self::time($text('starts_at'), 'start');
        $ends = self::time($text('ends_at'), 'end');
        if ($starts !== null && $ends !== null && $ends <= $starts) throw new RuntimeException('The end must be after the start.');

        $courseScope = $text('course_scope');
        $bundleScope = $text('bundle_scope');
        if (!in_array($courseScope, Promotion::SCOPES, true) || !in_array($bundleScope, Promotion::SCOPES, true)) throw new RuntimeException('Choose which courses and which bundles the promotion applies to.');
        if ($courseScope === Promotion::SCOPE_NONE && $bundleScope === Promotion::SCOPE_NONE) throw new RuntimeException('Apply the promotion to courses, to bundles, or to both.');
        $courses = $courseScope === Promotion::SCOPE_SELECTED ? self::ids($input, 'course') : [];
        if ($courseScope === Promotion::SCOPE_SELECTED) {
            if ($courses === []) throw new RuntimeException('Choose at least one course, or apply the promotion to all courses or to none.');
            if (count($courses) > 500) throw new RuntimeException('Choose at most 500 courses, or apply the promotion to all courses.');
            if (count($this->promotions->coursesById($courses)) !== count($courses)) throw new RuntimeException('One of the chosen courses no longer exists. Review the selection.');
        }
        $bundles = $bundleScope === Promotion::SCOPE_SELECTED ? self::ids($input, 'bundle') : [];
        if ($bundleScope === Promotion::SCOPE_SELECTED) {
            if ($bundles === []) throw new RuntimeException('Choose at least one bundle, or apply the promotion to all bundles or to none.');
            if (count($bundles) > 500) throw new RuntimeException('Choose at most 500 bundles, or apply the promotion to all bundles.');
            if (count($this->promotions->bundlesById($bundles)) !== count($bundles)) throw new RuntimeException('One of the chosen bundles no longer exists. Review the selection.');
        }

        $currency = $type === Promotion::FIXED_AMOUNT || $minimum !== null ? Money::platformCurrency() : null;
        return [[
            'code' => $code, 'name' => $name, 'description' => $description === '' ? null : $description,
            'discount_type' => $type, 'discount_value' => $value, 'currency' => $currency,
            'starts_at' => $starts?->format(DATE_ATOM), 'ends_at' => $ends?->format(DATE_ATOM), 'active' => ($input['active'] ?? '') === '1',
            'minimum_order_minor' => $minimum, 'maximum_total_uses' => $uses['maximum_total_uses'], 'maximum_uses_per_customer' => $uses['maximum_uses_per_customer'],
            'course_scope' => $courseScope, 'bundle_scope' => $bundleScope,
        ], $courses, $bundles];
    }

    /**
     * The selected courses or bundles kept from the editor plus the one chosen in its lookup.
     *
     * @param array<string,mixed> $input
     * @param 'course'|'bundle' $kind
     * @return list<int>
     */
    private static function ids(array $input, string $kind): array
    {
        $ids = [];
        foreach ([...(array) ($input[$kind . '_ids'] ?? []), $input['add_' . $kind . '_id'] ?? ''] as $value) {
            if (is_scalar($value) && preg_match('/^[1-9]\d{0,17}$/D', (string) $value) === 1) $ids[(int) $value] = true;
        }
        return array_keys($ids);
    }

    private static function amount(string $value): ?int
    {
        if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value) !== 1) return null;
        return Money::ofMajorUnits($value, Money::platformCurrency())->minorUnits;
    }

    private static function time(string $value, string $which): ?DateTimeImmutable
    {
        if ($value === '') return null;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, self::timezone());
        if ($time === false || $time->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Enter the ' . $which . ' as a date and time, or leave it empty.');
        return $time;
    }

    private function requireFreeCode(string $code, ?int $exceptId): void
    {
        if ($this->promotions->codeTaken($code, $exceptId)) throw self::codeTaken($code);
    }

    private static function codeTaken(string $code): RuntimeException
    {
        return new RuntimeException('Another promotion already uses the code ' . $code . '. Codes are not case-sensitive, so choose a different code.');
    }

    private static function usedPromotion(): RuntimeException
    {
        return new RuntimeException('Orders were placed with this promotion, so it is kept for their history. Deactivate it instead.');
    }

    /** @return list<string> the names of the fields that changed */
    private static function changes(Promotion $before, Promotion $after): array
    {
        $changed = [];
        foreach (['code', 'name', 'description', 'discountType', 'discountValue', 'currency', 'active', 'minimumOrderMinor', 'maximumTotalUses', 'maximumUsesPerCustomer', 'courseScope', 'bundleScope', 'courseIds', 'bundleIds'] as $field) {
            if ($before->{$field} !== $after->{$field}) $changed[] = $field;
        }
        foreach (['startsAt', 'endsAt'] as $field) {
            if ($before->{$field}?->getTimestamp() !== $after->{$field}?->getTimestamp()) $changed[] = $field;
        }
        return $changed;
    }

    /** The platform clock's present moment, which statuses and validity are judged against. */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
