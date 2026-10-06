<?php

declare(strict_types=1);

namespace CattoLearning\Bundle;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\{AccessPeriod, Money};
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Course bundles: ADMIN management and the public catalogue.
 *
 * A bundle is a catalogue and commerce offer, not a course: its courses keep their own pages, reader
 * and learning records. ADMIN chooses the bundle's price; the sum of its courses' current prices is
 * shown beside it for context only. The offer and the composition are current data, edited in place:
 * an order keeps the price, access period and courses it was placed with in its snapshot, so a later
 * change affects later purchases only. A bundle anyone bought is kept; only an unused draft is deleted.
 */
final class BundleService
{
    private const LIMITS = ['title' => 240, 'short_description' => 320, 'description_html' => 100000, 'cover_svg' => 200000];

    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly TransactionManager $transactions,
        private readonly AuditRepository $audit,
        private readonly ClockInterface $clock,
        private readonly CourseService $courses,
    ) {}

    /** @param array<string,mixed> $input */
    public function create(CurrentUser $actor, array $input): int
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $fields = $this->validateDetails($input, null);
        return $this->transactions->run(function () use ($actor, $fields): int {
            try {
                $id = $this->bundles->create($fields, $actor->id, $this->now());
            } catch (UniqueConstraintViolationException) {
                throw self::slugTaken((string) $fields['slug']);
            }
            $this->audit->record($actor->id, 'bundle.created', ['bundle_id' => $id, 'slug' => $fields['slug'], 'title' => $fields['title']]);
            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function updateDetails(CurrentUser $actor, int $id, array $input): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $fields = $this->validateDetails($input, $id);
        $this->transactions->run(function () use ($actor, $id, $fields): void {
            $before = $this->bundles->find($id, true) ?? throw self::missing();
            try {
                $this->bundles->update($id, $fields, $actor->id, $this->now());
            } catch (UniqueConstraintViolationException) {
                throw self::slugTaken((string) $fields['slug']);
            }
            $changed = [];
            foreach (['slug', 'title', 'available_from', 'available_until'] as $field) {
                $old = $before[$field] === null ? null : (in_array($field, ['available_from', 'available_until'], true) ? (new DateTimeImmutable((string) $before[$field]))->format(DATE_ATOM) : (string) $before[$field]);
                if ($old !== $fields[$field]) $changed[$field] = ['from' => $old, 'to' => $fields[$field]];
            }
            // Long texts are named, not copied into the audit log.
            foreach (['short_description', 'description_html', 'cover_svg'] as $field) {
                if ((string) $before[$field] !== $fields[$field]) $changed[$field] = 'changed';
            }
            if ($changed !== []) $this->audit->record($actor->id, 'bundle.updated', ['bundle_id' => $id, 'changed' => $changed]);
        });
    }

    public function addCourse(CurrentUser $actor, int $id, int $courseId): string
    {
        self::require($actor, 'BUNDLE.MANAGE');
        return $this->transactions->run(function () use ($actor, $id, $courseId): string {
            $this->bundles->find($id, true) ?? throw self::missing();
            $course = $courseId > 0 ? $this->bundles->course($courseId) : null;
            if ($course === null) throw new RuntimeException('Choose a course to add.');
            if ($this->bundles->hasCourse($id, $courseId)) throw new RuntimeException((string) $course['title'] . ' is already in this bundle.');
            try {
                $this->bundles->addCourse($id, $courseId);
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException((string) $course['title'] . ' is already in this bundle.');
            }
            $this->audit->record($actor->id, 'bundle.course_added', ['bundle_id' => $id, 'course_id' => $courseId, 'courses' => $this->courseIds($id)]);
            return (string) $course['title'];
        });
    }

    public function removeCourse(CurrentUser $actor, int $id, int $courseId): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $this->transactions->run(function () use ($actor, $id, $courseId): void {
            $bundle = $this->bundles->find($id, true) ?? throw self::missing();
            if (!$this->bundles->hasCourse($id, $courseId)) throw new RuntimeException('That course is not in this bundle.');
            if ($bundle['status'] === 'published' && count($this->bundles->courses($id)) <= BundleRules::MINIMUM_COURSES) {
                throw new RuntimeException('A published bundle needs at least two courses. Add another course first, or return the bundle to draft.');
            }
            $this->bundles->removeCourse($id, $courseId);
            $this->audit->record($actor->id, 'bundle.course_removed', ['bundle_id' => $id, 'course_id' => $courseId, 'courses' => $this->courseIds($id)]);
        });
    }

    public function moveCourse(CurrentUser $actor, int $id, int $courseId, string $direction): bool
    {
        self::require($actor, 'BUNDLE.MANAGE');
        if (!in_array($direction, ['up', 'down'], true)) throw new RuntimeException('Move a course up or down.');
        return $this->transactions->run(function () use ($actor, $id, $courseId, $direction): bool {
            $this->bundles->find($id, true) ?? throw self::missing();
            if (!$this->bundles->moveCourse($id, $courseId, $direction)) return false;
            $this->audit->record($actor->id, 'bundle.courses_reordered', ['bundle_id' => $id, 'courses' => $this->courseIds($id)]);
            return true;
        });
    }

    /**
     * Sets the bundle's price, access period and whether it is on sale. Edited in place, like a
     * course price: placed orders keep the offer they were placed with.
     *
     * @param array<string,mixed> $input
     */
    public function saveOffer(CurrentUser $actor, int $id, array $input): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $price = trim(str_replace([',', ' '], '', is_scalar($input['price'] ?? null) ? (string) $input['price'] : ''));
        if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $price) !== 1) throw new RuntimeException('Enter the bundle price as an amount with no more than two decimal places.');
        $minor = Money::ofMajorUnits($price, Money::platformCurrency())->minorUnits;
        if ($minor < 1) throw new RuntimeException('A bundle price must be greater than zero.');
        try {
            $period = AccessPeriod::fromInput($input['access_period_value'] ?? 0, $input['access_period_unit'] ?? 'years');
        } catch (\InvalidArgumentException $invalid) {
            throw new RuntimeException($invalid->getMessage());
        }
        $active = ($input['is_active'] ?? '') === '1';
        $this->transactions->run(function () use ($actor, $id, $minor, $period, $active): void {
            $before = $this->bundles->find($id, true) ?? throw self::missing();
            $this->bundles->saveOffer($id, $minor, Money::platformCurrency(), $period, $active, $actor->id, $this->now());
            $previous = $before['offer_id'] === null ? null : ['price_minor' => (int) $before['price_minor_units'], 'access_period_seconds' => (int) $before['access_period_seconds'], 'on_sale' => (bool) $before['offer_active']];
            $current = ['price_minor' => $minor, 'access_period_seconds' => $period, 'on_sale' => $active];
            if ($previous !== $current) $this->audit->record($actor->id, 'bundle.offer_updated', ['bundle_id' => $id, 'from' => $previous, 'to' => $current, 'currency' => Money::platformCurrency()]);
        });
    }

    /**
     * Draft, published or retired. Publishing needs a priced offer and at least two published
     * courses. Retiring stops new sales only: access already granted is never touched.
     */
    public function setStatus(CurrentUser $actor, int $id, string $status): bool
    {
        self::require($actor, 'BUNDLE.MANAGE');
        if (!in_array($status, BundleRules::STATUSES, true)) throw new RuntimeException('Choose draft, published or retired.');
        return $this->transactions->run(function () use ($actor, $id, $status): bool {
            $bundle = $this->bundles->find($id, true) ?? throw self::missing();
            if ($bundle['status'] === $status) return false;
            if ($status === 'published') {
                $courses = $this->bundles->courses($id);
                if (count($courses) < BundleRules::MINIMUM_COURSES) throw new RuntimeException('Add at least two courses before publishing the bundle.');
                foreach ($courses as $course) {
                    if ($course['status'] !== 'published') throw new RuntimeException((string) $course['title'] . ' is not published. A published bundle may contain published courses only.');
                }
                if ($bundle['offer_id'] === null) throw new RuntimeException('Set the bundle price before publishing it.');
            }
            $this->bundles->setStatus($id, $status, $actor->id, $this->now());
            $this->audit->record($actor->id, $status === 'retired' ? 'bundle.retired' : 'bundle.status_changed', ['bundle_id' => $id, 'from' => $bundle['status'], 'to' => $status]);
            return true;
        });
    }

    /** Deletes an unused draft. A bundle anyone bought is kept for its order history; retire it instead. */
    public function delete(CurrentUser $actor, int $id): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $this->transactions->run(function () use ($actor, $id): void {
            $bundle = $this->bundles->find($id, true) ?? throw self::missing();
            if ($this->bundles->sold($id)) throw self::kept();
            if ($bundle['status'] !== 'draft') throw new RuntimeException('Only a draft bundle can be deleted. Return it to draft first, or retire it.');
            try {
                $this->bundles->delete($id);
            } catch (ForeignKeyConstraintViolationException) {
                throw self::kept();
            }
            $this->audit->record($actor->id, 'bundle.deleted', ['bundle_id' => $id, 'slug' => $bundle['slug'], 'title' => $bundle['title']]);
        });
    }

    /**
     * A bundle with its offer, its courses and what the catalogue says about it now.
     *
     * @param array<string,mixed> $bundle a bundle row with its offer
     * @param list<int> $ownedCourseIds courses the viewer already has open access to
     * @return array<string,mixed>
     */
    public function present(array $bundle, array $ownedCourseIds = []): array
    {
        $courses = $this->bundles->courses((int) $bundle['id']);
        $currency = (string) ($bundle['currency_code'] ?? Money::platformCurrency());
        $price = $bundle['offer_id'] === null ? null : (int) $bundle['price_minor_units'];
        $owned = array_values(array_intersect(array_map(static fn(array $c): int => (int) $c['course_id'], $courses), $ownedCourseIds));
        return $bundle + [
            'courses' => $courses,
            'course_count' => count($courses),
            'price_label' => $price === null ? null : Money::strictMinorUnits($price, $currency)->format(),
            'access_label' => $bundle['offer_id'] === null ? null : AccessPeriod::label((int) $bundle['access_period_seconds']),
            'comparison' => self::comparison($courses, $price, $currency),
            'unavailable' => BundleRules::unavailableReason($bundle, $courses, $this->clock->now()),
            'owned_count' => count($owned),
        ];
    }

    /**
     * The public bundle catalogue: published bundles as catalogue cards. A bundle is shown with the
     * course card (its own address, link and facts), so it looks like any catalogue item.
     *
     * @return list<array<string,mixed>>
     */
    public function catalogue(int $limit, int $offset): array
    {
        return array_map(function (array $bundle): array {
            $presented = $this->present($bundle);
            $facts = [['label' => 'Courses', 'value' => $presented['course_count']], ['label' => 'Access', 'value' => $presented['access_label'] ?? '—'], ['label' => 'Price', 'value' => $presented['price_label'] ?? '—']];
            if ($presented['unavailable'] !== null) $facts[] = ['label' => 'Availability', 'value' => 'Not available now'];
            return ['title' => (string) $bundle['title'], 'slug' => (string) $bundle['slug'], 'href' => '/bundles/' . $bundle['slug'], 'link_label' => 'View bundle',
                'summary' => (string) $bundle['short_description'], 'cover_svg' => (string) $bundle['cover_svg'], 'cover_media_public_id' => null,
                'cover_initial' => mb_strtoupper(mb_substr((string) $bundle['title'], 0, 1)), 'facts' => $facts];
        }, $this->bundles->published($limit, $offset));
    }

    /**
     * A bundle's public page: null for a draft or an unknown slug. A retired bundle is still shown,
     * as no longer sold, so earlier purchasers' links keep working.
     *
     * @return array<string,mixed>|null
     */
    public function detail(string $slug, ?CurrentUser $viewer): ?array
    {
        $bundle = $this->bundles->findBySlug($slug);
        if ($bundle === null || $bundle['status'] === 'draft') return null;
        $courseIds = $this->courseIds((int) $bundle['id']);
        $presented = $this->present($bundle, $viewer === null ? [] : $this->bundles->ownedCourseIds($viewer->id, $courseIds));
        $presented['course_cards'] = $this->courses->cardsByIds($courseIds);
        return $presented;
    }

    /**
     * The editor's values for a bundle, or for a new one.
     *
     * @param array<string,mixed>|null $bundle
     * @return array<string,mixed>
     */
    public function form(?array $bundle): array
    {
        $time = static fn(mixed $value): string => $value === null ? '' : (new DateTimeImmutable((string) $value))->setTimezone(self::timezone())->format('Y-m-d\TH:i');
        [$value, $unit] = AccessPeriod::decompose((int) ($bundle['access_period_seconds'] ?? 31536000));
        return [
            'title' => (string) ($bundle['title'] ?? ''), 'slug' => (string) ($bundle['slug'] ?? ''), 'short_description' => (string) ($bundle['short_description'] ?? ''),
            'description_html' => (string) ($bundle['description_html'] ?? ''), 'cover_svg' => (string) ($bundle['cover_svg'] ?? ''),
            'available_from' => $time($bundle['available_from'] ?? null), 'available_until' => $time($bundle['available_until'] ?? null),
            'price' => isset($bundle['price_minor_units']) ? Money::strictMinorUnits((int) $bundle['price_minor_units'], (string) $bundle['currency_code'])->toMajorUnits() : '',
            'access_period_value' => $value, 'access_period_unit' => $unit, 'is_active' => $bundle === null || $bundle['offer_id'] === null || (bool) $bundle['offer_active'],
        ];
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * The informational comparison with buying the courses one by one at their current default
     * prices. Null when any course has no current comparable price, so no saving is ever claimed
     * that cannot be shown; the saving is null when the bundle is not cheaper or has no price yet.
     *
     * @param list<array<string,mixed>> $courses
     * @return array{individual_minor:int,individual_label:string,saving_minor:?int,saving_label:?string}|null
     */
    public static function comparison(array $courses, ?int $priceMinor, string $currency): ?array
    {
        if ($courses === []) return null;
        $total = 0;
        foreach ($courses as $course) {
            if ($course['default_price_minor_units'] === null || (int) $course['default_price_minor_units'] < 1 || (string) $course['default_currency_code'] !== $currency) return null;
            $total += (int) $course['default_price_minor_units'];
        }
        $saving = $priceMinor !== null && $total > $priceMinor ? $total - $priceMinor : null;
        return ['individual_minor' => $total, 'individual_label' => Money::strictMinorUnits($total, $currency)->format(),
            'saving_minor' => $saving, 'saving_label' => $saving === null ? null : Money::strictMinorUnits($saving, $currency)->format()];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validateDetails(array $input, ?int $id): array
    {
        $text = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $title = $text('title');
        if ($title === '' || mb_strlen($title) > self::LIMITS['title']) throw new RuntimeException('Give the bundle a title of at most 240 characters.');
        $slug = $text('slug') === '' ? BundleRules::slug($title) : strtolower($text('slug'));
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 180) throw new RuntimeException('Use lower-case letters, digits and single hyphens for the web address.');
        if ($this->bundles->slugTaken($slug, $id)) throw self::slugTaken($slug);
        $fields = ['title' => $title, 'slug' => $slug];
        foreach (['short_description', 'description_html', 'cover_svg'] as $field) {
            $value = $text($field);
            if (mb_strlen($value) > self::LIMITS[$field]) throw new RuntimeException('The ' . str_replace(['_html', '_'], ['', ' '], $field) . ' is too long.');
            if (preg_match('//u', $value) !== 1) throw new RuntimeException('The ' . str_replace(['_html', '_'], ['', ' '], $field) . ' is not valid text.');
            $fields[$field] = $value;
        }
        if ($fields['cover_svg'] !== '' && !str_starts_with($fields['cover_svg'], '<svg')) throw new RuntimeException('The cover must be SVG markup starting with <svg, or left empty.');
        $from = self::time($text('available_from'), 'available from');
        $until = self::time($text('available_until'), 'available until');
        if ($from !== null && $until !== null && $until <= $from) throw new RuntimeException('The end of the sales period must be after its start.');
        return $fields + ['available_from' => $from?->format(DATE_ATOM), 'available_until' => $until?->format(DATE_ATOM)];
    }

    private static function time(string $value, string $which): ?DateTimeImmutable
    {
        if ($value === '') return null;
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, self::timezone());
        if ($time === false || $time->format('Y-m-d\TH:i') !== $value) throw new RuntimeException('Enter "' . $which . '" as a date and time, or leave it empty.');
        return $time;
    }

    /** @return list<int> */
    private function courseIds(int $bundleId): array
    {
        return array_map(static fn(array $course): int => (int) $course['course_id'], $this->bundles->courses($bundleId));
    }

    private function now(): string
    {
        return $this->clock->now()->format(DATE_ATOM);
    }

    private static function require(CurrentUser $actor, string $permission): void
    {
        if (!$actor->hasPermission($permission)) throw new AccessDeniedHttpException('You cannot manage bundles.');
    }

    private static function missing(): RuntimeException
    {
        return new RuntimeException('This bundle no longer exists.');
    }

    private static function slugTaken(string $slug): RuntimeException
    {
        return new RuntimeException('Another bundle already uses the web address ' . $slug . '. Choose a different one.');
    }

    private static function kept(): RuntimeException
    {
        return new RuntimeException('This bundle has been sold, so it is kept for its order history. Retire it to stop selling it.');
    }
}
