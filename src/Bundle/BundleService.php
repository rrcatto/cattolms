<?php

declare(strict_types=1);

namespace CattoLearning\Bundle;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Course\CourseCode;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\AuditRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\{AccessPeriod, Money, WasPrice};
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
 * The offer may carry a was price (owner, 2026-10-20), a higher price shown struck through beside the
 * bundle price; only the bundle price is charged. Its form fills an empty was price with the courses' total and says, as
 * the price is typed, what percentage it is off (owner, 2026-11-09); a card shows the saving's percentage in a badge of
 * its own beside the amount.
 */
final class BundleService
{
    private const LIMITS = ['title' => 240, 'short_description' => 320, 'description_html' => 100000, 'cover_svg' => 200000];

    /** A small number of courses in words on a page ("the five beside it"); ten and more stay figures. */
    private const NUMBER_WORDS = [1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine'];

    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly TransactionManager $transactions,
        private readonly AuditRepository $audit,
        private readonly ClockInterface $clock,
        private readonly CourseService $courses,
        private readonly CommerceRepository $commerce,
        private readonly ?\CattoPHP\Search\SharePictures $sharePictures = null,
        private readonly ?BundlePositions $positions = null,
    ) {}

    /**
     * Makes a bundle as a draft. A status chosen on the form (owner, 2026-11-06: "On the bundle form (new or edit), I can't
     * set the status ... use radio buttons") is then set by the same rules as ever: one the publish checks refuse leaves
     * the new bundle a draft, and $refused says why.
     *
     * @param array<string,mixed> $input
     * @param-out string|null $refused
     */
    public function create(CurrentUser $actor, array $input, ?string &$refused = null): int
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $refused = null;
        $fields = $this->validateDetails($input, null);
        $position = BundlePositions::typed($input['catalogue_position'] ?? null);
        $status = self::chosenStatus($input);
        $id = $this->transactions->run(function () use ($actor, $fields, $position): int {
            try {
                $id = $this->bundles->create($fields, $actor->id, $this->now());
            } catch (UniqueConstraintViolationException) {
                throw $this->taken($fields, null);
            }
            // The position typed, or the next free number in its code's band (owner, 2026-11-12).
            $this->place($id, $position);
            $this->audit->record($actor->id, 'bundle.created', ['bundle_id' => $id, 'code' => $fields['code'], 'slug' => $fields['slug'], 'title' => $fields['title']]);
            return $id;
        });
        if ($status !== null && $status !== 'draft') {
            try {
                $this->setStatus($actor, $id, $status);
            } catch (RuntimeException $notYet) {
                $refused = $notYet->getMessage();
            }
        }
        // Its share picture is drawn as it saves, never on a request (owner, 2026-11-06; CattoPHP\Search\SharePictures).
        $this->sharePictures?->make($fields['cover_svg']);
        return $id;
    }

    /**
     * Saves the bundle's details and, when the form chose one, its status, together or not at all: a status the publish
     * checks refuse leaves everything as it was, with why.
     *
     * @param array<string,mixed> $input
     */
    public function updateDetails(CurrentUser $actor, int $id, array $input): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $fields = $this->validateDetails($input, $id);
        // A form with the position field sets it (owner, 2026-11-12); left empty, the next free number in its band.
        $askedPosition = array_key_exists('catalogue_position', $input);
        $position = $askedPosition ? BundlePositions::typed($input['catalogue_position']) : null;
        $status = self::chosenStatus($input);
        $this->transactions->run(function () use ($actor, $id, $fields, $status, $askedPosition, $position): void {
            $before = $this->bundles->find($id, true) ?? throw self::missing();
            // Refused before anything is written, so the details and the status go together or not at all.
            if ($status === 'published' && $before['status'] !== 'published') {
                $refusal = $this->publishRefusal($id, $before['offer_id'] !== null);
                if ($refusal !== null) throw new RuntimeException($refusal);
            }
            try {
                $this->bundles->update($id, $fields, $actor->id, $this->now());
            } catch (UniqueConstraintViolationException) {
                throw $this->taken($fields, $id);
            }
            $changed = [];
            foreach (['code', 'slug', 'title', 'available_from', 'available_until'] as $field) {
                $old = $before[$field] === null ? null : (in_array($field, ['available_from', 'available_until'], true) ? (new DateTimeImmutable((string) $before[$field]))->format(DATE_ATOM) : (string) $before[$field]);
                if ($old !== $fields[$field]) $changed[$field] = ['from' => $old, 'to' => $fields[$field]];
            }
            // Long texts are named, not copied into the audit log.
            foreach (['short_description', 'description_html', 'cover_svg'] as $field) {
                if ((string) $before[$field] !== $fields[$field]) $changed[$field] = 'changed';
            }
            if ($askedPosition) {
                $old = $before['catalogue_position'] === null ? null : (int) $before['catalogue_position'];
                $now = $this->place($id, $position, $old);
                if ($now !== $old) $changed['catalogue_position'] = ['from' => $old, 'to' => $now];
            }
            if ($changed !== []) $this->audit->record($actor->id, 'bundle.updated', ['bundle_id' => $id, 'changed' => $changed]);
            if ($status !== null) $this->setStatus($actor, $id, $status);
        });
        // Its share picture is drawn as it saves, never on a request (owner, 2026-11-06; CattoPHP\Search\SharePictures).
        $this->sharePictures?->make($fields['cover_svg']);
    }

    /**
     * A person stars or unstars a bundle (owner, 2026-11-06: "Allow bundles to be added to favourites"), as a course is
     * starred: the state asked for, so a repeated request changes nothing, and only a bundle the catalogue shows (not a
     * draft) can be starred. Answers whether anything changed.
     */
    public function setFavourite(int $userId, int $bundleId, bool $favourite): bool
    {
        return $this->transactions->run(function () use ($userId, $bundleId, $favourite): bool {
            $bundle = $this->bundles->find($bundleId);
            if ($favourite && ($bundle === null || $bundle['status'] === 'draft')) throw new RuntimeException('That bundle could not be added to your favourites.');
            if (!$this->bundles->setFavourite($userId, $bundleId, $favourite)) return false;
            $this->audit->record($userId, $favourite ? 'bundle.favourited' : 'bundle.unfavourited', ['bundle_id' => $bundleId]);
            return true;
        });
    }

    /**
     * Marks each bundle card with whether this person starred it, for its star. With nobody signed in every card is left
     * as it is.
     *
     * @param list<array<string,mixed>> $cards cards as card() gives them
     * @return list<array<string,mixed>>
     */
    public function markFavourites(?int $userId, array $cards): array
    {
        if ($userId === null || $cards === []) return $cards;
        $starred = array_flip($this->bundles->favouriteIds($userId, array_map(static fn(array $card): int => (int) $card['bundle_id'], $cards)));
        foreach ($cards as &$card) $card['is_favourite'] = isset($starred[(int) $card['bundle_id']]);
        unset($card);
        return $cards;
    }

    /**
     * Bundles as catalogue cards, in the order given, leaving out drafts and any no longer here (a person's Favourites).
     *
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    public function cardsByIds(array $ids): array
    {
        $presented = [];
        $courses = $this->bundles->coursesOf($ids);
        foreach ($ids as $id) {
            $bundle = $this->bundles->find($id);
            if ($bundle === null || $bundle['status'] === 'draft') continue;
            $presented[] = $this->present($bundle, [], $courses[$id] ?? []);
        }
        return $this->cards($presented);
    }

    /**
     * Saves a bundle edited in place in the ADMIN list (owner, 2026-11-06: "In the bundle list, it must be editable, just
     * like courses are. these fields must be editable: bundle code, title, status, price, start & end dates, access
     * period"): its code, title and sale dates as the bundle form saves them (its web address and words kept), its price,
     * was price and access period as Price and access saves them (whether it is on sale kept; a bundle with no price and
     * none typed stays without one), then its status by the same checks as ever. Everything is saved together or nothing is.
     *
     * @param array<string,mixed> $input
     */
    public function saveRow(CurrentUser $actor, int $id, array $input): void
    {
        self::require($actor, 'BUNDLE.MANAGE');
        $this->transactions->run(function () use ($actor, $id, $input): void {
            $bundle = $this->bundles->find($id, true) ?? throw self::missing();
            $form = $this->form($bundle);
            $typed = static fn(string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
            $details = ['code' => $typed('code'), 'title' => $typed('title'), 'slug' => $form['slug'], 'short_description' => $form['short_description'],
                'description_html' => $form['description_html'], 'cover_svg' => $form['cover_svg'], 'available_from' => $typed('available_from'), 'available_until' => $typed('available_until')];
            if (array_key_exists('catalogue_position', $input)) $details['catalogue_position'] = $typed('catalogue_position');
            $offer = $bundle['offer_id'] !== null || $typed('price') !== '' || $typed('was_price') !== '' ? ['price' => $typed('price'), 'was_price' => $typed('was_price'),
                'access_period_value' => $typed('access_period_value'), 'access_period_unit' => $typed('access_period_unit'), 'is_active' => $form['is_active'] ? '1' : ''] : null;
            $status = self::chosenStatus($input);
            // Everything is checked before anything is written, so a refusal leaves the bundle as it was.
            $this->validateDetails($details, $id);
            if ($offer !== null) self::offerInput($offer);
            if ($status === 'published' && $bundle['status'] !== 'published') {
                $refusal = $this->publishRefusal($id, $offer !== null);
                if ($refusal !== null) throw new RuntimeException($refusal);
            }
            $this->updateDetails($actor, $id, $details);
            if ($offer !== null) $this->saveOffer($actor, $id, $offer);
            if ($status !== null) $this->setStatus($actor, $id, $status);
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
        [$minor, $was, $period, $active] = self::offerInput($input);
        $this->transactions->run(function () use ($actor, $id, $minor, $was, $period, $active): void {
            $before = $this->bundles->find($id, true) ?? throw self::missing();
            $this->bundles->saveOffer($id, $minor, Money::platformCurrency(), $period, $active, $actor->id, $this->now(), $was);
            $previous = $before['offer_id'] === null ? null : ['price_minor' => (int) $before['price_minor_units'], 'was_price_minor' => $before['was_price_minor_units'] === null ? null : (int) $before['was_price_minor_units'], 'access_period_seconds' => (int) $before['access_period_seconds'], 'on_sale' => (bool) $before['offer_active']];
            $current = ['price_minor' => $minor, 'was_price_minor' => $was, 'access_period_seconds' => $period, 'on_sale' => $active];
            if ($previous !== $current) $this->audit->record($actor->id, 'bundle.offer_updated', ['bundle_id' => $id, 'from' => $previous, 'to' => $current, 'currency' => Money::platformCurrency()]);
        });
    }

    /**
     * An offer as typed, checked: the price and was price in minor units, the access period in seconds, whether it is on
     * sale.
     *
     * @param array<string,mixed> $input
     * @return array{0:int,1:?int,2:int,3:bool}
     */
    private static function offerInput(array $input): array
    {
        $price = trim(str_replace([',', ' '], '', is_scalar($input['price'] ?? null) ? (string) $input['price'] : ''));
        if (preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $price) !== 1) throw new RuntimeException('Enter the bundle price as an amount with no more than two decimal places.');
        $minor = Money::ofMajorUnits($price, Money::platformCurrency())->minorUnits;
        if ($minor < 1) throw new RuntimeException('A bundle price must be greater than zero.');
        try {
            $was = WasPrice::fromInput($input['was_price'] ?? '', $minor);
        } catch (\InvalidArgumentException $invalid) {
            throw new RuntimeException($invalid->getMessage());
        }
        try {
            $period = AccessPeriod::fromInput($input['access_period_value'] ?? 0, $input['access_period_unit'] ?? 'years');
        } catch (\InvalidArgumentException $invalid) {
            throw new RuntimeException($invalid->getMessage());
        }
        return [$minor, $was, $period, ($input['is_active'] ?? '') === '1'];
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
                $refusal = $this->publishRefusal($id, $bundle['offer_id'] !== null);
                if ($refusal !== null) throw new RuntimeException($refusal);
            }
            $this->bundles->setStatus($id, $status, $actor->id, $this->now());
            $this->audit->record($actor->id, $status === 'retired' ? 'bundle.retired' : 'bundle.status_changed', ['bundle_id' => $id, 'from' => $bundle['status'], 'to' => $status]);
            return true;
        });
    }

    /**
     * Why the bundle cannot be published: fewer than two courses, a course not published, or no price; null when it can.
     */
    private function publishRefusal(int $id, bool $priced): ?string
    {
        $courses = $this->bundles->courses($id);
        if (count($courses) < BundleRules::MINIMUM_COURSES) return 'Add at least two courses before publishing the bundle.';
        foreach ($courses as $course) {
            if ($course['status'] !== 'published') return (string) $course['title'] . ' is not published. A published bundle may contain published courses only.';
        }
        return $priced ? null : 'Set the bundle price before publishing it.';
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
     * @param list<array<string,mixed>>|null $courses its courses when already read (BundleRepository::coursesOf)
     * @return array<string,mixed>
     */
    public function present(array $bundle, array $ownedCourseIds = [], ?array $courses = null): array
    {
        $courses ??= $this->bundles->courses((int) $bundle['id']);
        $currency = (string) ($bundle['currency_code'] ?? Money::platformCurrency());
        $price = $bundle['offer_id'] === null ? null : (int) $bundle['price_minor_units'];
        $owned = array_values(array_intersect(array_map(static fn(array $c): int => (int) $c['course_id'], $courses), $ownedCourseIds));
        $comparison = self::comparison($courses, $price, $currency);
        $save = self::saving($price, $bundle['was_price_minor_units'] ?? null, $comparison);
        // The saving's percentage, of what it is saved from: the was price, else the courses' total (owner, 2026-11-09).
        $from = is_numeric($bundle['was_price_minor_units'] ?? null) && $price !== null && (int) $bundle['was_price_minor_units'] > $price ? (int) $bundle['was_price_minor_units'] : ($comparison['individual_minor'] ?? 0);
        $percent = $save === null || $price === null ? null : WasPrice::percentOff($price, $from);
        return $bundle + [
            'courses' => $courses,
            'course_count' => count($courses),
            'price_label' => $price === null ? null : Money::strictMinorUnits($price, $currency)->format(),
        ] + ($price === null ? ['was_label' => null, 'saving_label' => null, 'saving_percent' => null] : WasPrice::labels($price, $bundle['was_price_minor_units'] ?? null, $currency)) + [
            'access_label' => $bundle['offer_id'] === null ? null : AccessPeriod::label((int) $bundle['access_period_seconds']),
            'comparison' => $comparison,
            'save_minor' => $save,
            'save_label' => $save === null ? null : Money::strictMinorUnits($save, $currency)->format(),
            'save_percent' => $percent,
            'unavailable' => BundleRules::unavailableReason($bundle, $courses, $this->clock->now()),
            // A published bundle whose sale starts later is shown with when (owner, 2026-11-06): the long form on its page,
            // the short one on a card or a note.
            'on_sale_from' => ($start = BundleRules::startsLater($bundle, $courses, $this->clock->now())) === null ? null : $start->format(DATE_ATOM),
            'on_sale_from_label' => $start === null ? null : 'On sale from ' . BundleRules::saleDate($start),
            'on_sale_from_short' => $start === null ? null : 'On sale from ' . BundleRules::saleDate($start, true),
            'owned_count' => count($owned),
        ];
    }

    /**
     * The public bundle catalogue: published bundles as catalogue cards. A bundle is shown with the
     * course card (its own address, link and facts), so it looks like any catalogue item.
     *
     * @return list<array<string,mixed>>
     */
    public function catalogue(int $limit, int $offset, ?CurrentUser $viewer = null): array
    {
        $rows = $this->bundles->published($limit, $offset);
        $courses = $this->bundles->coursesOf(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        return $this->markFavourites($viewer?->id, $this->cards(array_map(fn(array $bundle): array => $this->present($bundle, [], $courses[(int) $bundle['id']] ?? []), $rows)));
    }

    /**
     * A company account is shown no bundle, except a Company Administrator, who buys bundles for the company as bundle credits
     * (owner, 2026-11-11: "companies need to be able to buy bundles"); a private account and a visitor see them all.
     */
    private function hidesBundlesFrom(?CurrentUser $viewer): bool
    {
        return $viewer !== null && $this->commerce->isCompanyAccount($viewer->id) && !$viewer->hasPermission('COMPANY.CREDIT.MANAGE');
    }

    /**
     * The bundles a course's page offers under its buy button (owner, 2026-11-06, the approved mockup):
     * each published bundle holding the course that this visitor could buy now, the larger saving
     * first. A company account is offered none (OrderService::COMPANY_ACCOUNT), except a Company Administrator, who buys
     * bundles for the company as bundle credits (owner, 2026-11-11), and no visitor is offered one BundleRules would not sell now
     * (off sale, outside its dates, no price) or one an order of theirs already holds, which checkout
     * would refuse (OrderService::validateBundle). A visitor who already has some of its courses is
     * offered it at the same price and told how many, as the bundle's own page does. One whose sale starts later is
     * offered too, saying when (owner, 2026-11-06), the rest as BundleRules says.
     *
     * @return list<array<string,mixed>> each as present() gives it, with its address ('href') and the
     *         number of its other courses in words ('others_words')
     */
    public function forCourse(int $courseId, ?CurrentUser $viewer): array
    {
        if ($this->hidesBundlesFrom($viewer)) return [];
        $notes = [];
        foreach ($this->buyable($this->bundles->publishedFor(['course' => $courseId]), $viewer) as $bundle) {
            if ($viewer !== null && $this->commerce->hasPayableBundle($viewer->id, (int) $bundle['id'])) continue;
            $others = (int) $bundle['course_count'] - 1;
            $notes[] = $bundle + ['href' => '/bundles/' . $bundle['slug'], 'others_words' => self::NUMBER_WORDS[$others] ?? (string) $others];
        }
        return $notes;
    }

    /**
     * The catalogue's "Save with a bundle" row (owner, 2026-11-06, the approved mockup): on All courses
     * (no category) every published bundle that can be bought now, on a category's page those holding a
     * course in its branch, drawn with the Bundles page's cards, the larger saving first. A company
     * account sees none. The catalogue pages them six at a time in a section of their own, whatever
     * page of courses or search is shown (owner, 2026-11-07, the approved mockup); never on a tag's page.
     *
     * @return list<array<string,mixed>> course cards, as catalogue() gives them
     */
    public function catalogueRow(?int $categoryId, ?CurrentUser $viewer): array
    {
        if ($this->hidesBundlesFrom($viewer)) return [];
        return $this->markFavourites($viewer?->id, $this->cards($this->buyable($this->bundles->publishedFor($categoryId === null ? [] : ['category' => $categoryId]), null)));
    }

    /**
     * What a bundle saves, for its "Save R..." (the approved mockup): its was price less its price when it
     * has one, so the price struck through beside it adds up; otherwise what its courses cost more one by
     * one at their current prices, as its page's comparison says; null when it saves nothing either way.
     *
     * @param array{saving_minor:?int}|null $comparison
     */
    public static function saving(?int $priceMinor, mixed $wasMinor, ?array $comparison): ?int
    {
        if ($priceMinor === null) return null;
        if (is_numeric($wasMinor) && (int) $wasMinor > $priceMinor) return (int) $wasMinor - $priceMinor;
        return $comparison['saving_minor'] ?? null;
    }

    /**
     * These published bundles presented, keeping those that can be bought now or once their sale starts, the larger saving first
     * (in the order given where two save the same). Their courses are read at once, and what the viewer
     * already has once.
     *
     * @param list<array<string,mixed>> $rows bundle rows with their offer
     * @return list<array<string,mixed>>
     */
    private function buyable(array $rows, ?CurrentUser $viewer): array
    {
        $courses = $this->bundles->coursesOf(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        $courseIds = array_values(array_unique(array_merge([], ...array_map(static fn(array $list): array => array_map(static fn(array $c): int => (int) $c['course_id'], $list), array_values($courses)))));
        $owned = $viewer === null ? [] : $this->bundles->ownedCourseIds($viewer->id, $courseIds);
        $presented = [];
        foreach ($rows as $row) {
            $bundle = $this->present($row, $owned, $courses[(int) $row['id']] ?? []);
            if ($bundle['unavailable'] === null || $bundle['on_sale_from'] !== null) $presented[] = $bundle;
        }
        usort($presented, static fn(array $a, array $b): int => ($b['save_minor'] ?? 0) <=> ($a['save_minor'] ?? 0));
        return $presented;
    }

    /**
     * Bundles as catalogue cards, with their courses' covers read at once.
     *
     * @param list<array<string,mixed>> $presented as present() gives them
     * @return list<array<string,mixed>>
     */
    private function cards(array $presented): array
    {
        $courseIds = [];
        foreach ($presented as $bundle) foreach ($bundle['courses'] as $course) $courseIds[] = (int) $course['course_id'];
        $covers = $this->bundles->coverVersions(array_values(array_unique($courseIds)));
        return array_map(static fn(array $bundle): array => self::card($bundle, $covers), $presented);
    }

    /**
     * A bundle as a catalogue card (partials/bundle-card.html.twig, the approved mockup of 2026-11-07: a card of its own,
     * not a course's): its own drawing, its address, short description, how many courses and their covers, its price with the was price,
     * what it saves while it can be bought, its access and when a later sale starts. The facts rows and link label stay
     * for Favourites' list, which reads the price from them.
     *
     * @param array<string,mixed> $presented as present() gives it
     * @param array<int,string> $covers course id => cover version, as coverVersions() gives them
     * @return array<string,mixed>
     */
    private static function card(array $presented, array $covers = []): array
    {
        $facts = [['label' => 'Courses', 'value' => $presented['course_count']], ['label' => 'Access', 'value' => $presented['access_label'] ?? '—'], ['label' => 'Price', 'value' => $presented['price_label'] ?? '—', 'was' => $presented['was_label']]];
        $later = $presented['on_sale_from'] !== null;
        if ($presented['unavailable'] !== null && !$later) $facts[] = ['label' => 'Availability', 'value' => 'Not available now'];
        $courseCovers = [];
        foreach ($presented['courses'] as $course) {
            $version = $covers[(int) $course['course_id']] ?? null;
            $courseCovers[] = ['title' => (string) $course['title'], 'cover' => $version === null ? null : '/courses/' . $course['slug'] . '/cover.svg?v=' . $version,
                'initial' => mb_strtoupper(mb_substr((string) $course['title'], 0, 1))];
        }
        // bundle_id gives the card its star (owner, 2026-11-06), and a bundle on sale later says when, under its price.
        return ['bundle_id' => (int) $presented['id'], 'title' => (string) $presented['title'], 'slug' => (string) $presented['slug'], 'href' => '/bundles/' . $presented['slug'], 'link_label' => 'View bundle',
            'summary' => (string) $presented['short_description'], 'cover_svg' => (string) $presented['cover_svg'], 'cover_media_public_id' => null,
            // Its own drawing, the strip across the top of its card (owner, 2026-11-07, the approved mockup).
            'cover_href' => str_starts_with(ltrim((string) $presented['cover_svg']), '<svg') ? '/bundles/' . $presented['slug'] . '/cover.svg?v=' . substr(md5((string) $presented['cover_svg']), 0, 12) : null,
            'cover_initial' => mb_strtoupper(mb_substr((string) $presented['title'], 0, 1)), 'facts' => $facts,
            'course_count' => (int) $presented['course_count'], 'course_covers' => $courseCovers,
            'access_label' => $presented['access_label'], 'price_label' => $presented['price_label'], 'was_label' => $presented['was_label'],
            'not_available' => $presented['unavailable'] !== null && !$later,
            'saving_badge' => ($presented['unavailable'] === null || $later) && $presented['save_label'] !== null ? 'Save ' . $presented['save_label'] : null,
            // The saving's percentage in a badge of its own beside the amount (owner, 2026-11-09), rounded down.
            'saving_percent_badge' => ($presented['unavailable'] === null || $later) && $presented['save_label'] !== null && ($presented['save_percent'] ?? 0) >= 1 ? $presented['save_percent'] . '% off' : null,
            'availability_badge' => $later ? (string) $presented['on_sale_from_short'] : null];
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
        $presented['bundle_id'] = (int) $bundle['id'];
        $presented['is_favourite'] = $viewer !== null && $this->bundles->favouriteIds($viewer->id, [(int) $bundle['id']]) !== [];
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
        // The courses' total (owner, 2026-11-09: "auto-populate the Was Price with the sum total of the prices of all the
        // courses in the bundle"): an empty was price is filled with it while it is higher than the price, and saved with
        // the price. Null when a course has no price of its own in the site's currency.
        $currency = (string) ($bundle['currency_code'] ?? Money::platformCurrency());
        $price = isset($bundle['price_minor_units']) ? (int) $bundle['price_minor_units'] : null;
        $total = isset($bundle['id']) ? (self::comparison($this->bundles->courses((int) $bundle['id']), $price, $currency)['individual_minor'] ?? null) : null;
        $was = is_numeric($bundle['was_price_minor_units'] ?? null) ? (int) $bundle['was_price_minor_units'] : null;
        $filled = $was === null && $total !== null && ($price === null || $total > $price);
        return [
            'code' => (string) ($bundle['code'] ?? ''), 'status' => (string) ($bundle['status'] ?? 'draft'),
            'position' => isset($bundle['catalogue_position']) ? (string) $bundle['catalogue_position'] : '',
            'title' => (string) ($bundle['title'] ?? ''), 'slug' => (string) ($bundle['slug'] ?? ''), 'short_description' => (string) ($bundle['short_description'] ?? ''),
            'description_html' => (string) ($bundle['description_html'] ?? ''), 'cover_svg' => (string) ($bundle['cover_svg'] ?? ''),
            'available_from' => $time($bundle['available_from'] ?? null), 'available_until' => $time($bundle['available_until'] ?? null),
            'price' => isset($bundle['price_minor_units']) ? Money::strictMinorUnits((int) $bundle['price_minor_units'], (string) $bundle['currency_code'])->toMajorUnits() : '',
            'was_price' => WasPrice::input($filled ? $total : $was),
            'was_from_courses' => $filled,
            'courses_total' => $total === null ? '' : WasPrice::input($total),
            'courses_total_label' => $total === null ? '' : Money::strictMinorUnits($total, $currency)->format(),
            'discount' => self::discountWords($price, $filled ? $total : $was, $currency),
            'currency' => $currency, 'locale' => str_replace('_', '-', \CattoLearning\Support\Env::string('APP_LOCALE') ?: 'en_ZA'),
            'access_period_value' => $value, 'access_period_unit' => $unit, 'is_active' => $bundle === null || $bundle['offer_id'] === null || (bool) $bundle['offer_active'],
        ];
    }

    /**
     * What a bundle's price form says beside the price (owner, 2026-11-09: "When I type in the bundle price, can you display
     * the percentage discount?"): its percentage off the was price and the amount saved, rounded down as every saving is.
     * bundle_price_controller.js says the same as the price is typed.
     */
    public static function discountWords(?int $priceMinor, ?int $wasMinor, string $currency): string
    {
        if ($priceMinor === null || $wasMinor === null) return '';
        $percent = WasPrice::percentOff($priceMinor, $wasMinor);
        if ($percent === null) return 'The was price must be higher than the bundle price.';
        return $percent . '% off the was price: the buyer saves ' . Money::strictMinorUnits($wasMinor - $priceMinor, $currency)->format() . '.';
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
        $fields = ['code' => $this->code($text('code'), $title, $id), 'title' => $title, 'slug' => $slug];
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

    /**
     * A bundle's position as a form left it (owner, 2026-11-12): the number typed, or, left empty, the next free number in
     * its code's band (none when its prefix has no band). Answers the position it now has.
     */
    private function place(int $id, ?int $typed, ?int $current = null): ?int
    {
        if ($typed !== $current) $this->bundles->setPosition($id, $typed);
        if ($typed !== null) return $typed;
        return $this->positions?->fill($id);
    }

    /**
     * The prefix a suggested code starts with (owner, 2026-11-12: a bundle prefix system like the courses'): the one most of
     * its courses' codes share, the first course's on a tie; BDL for a bundle with no courses yet.
     */
    private function prefixFromCourses(?int $id): string
    {
        if ($id === null) return BundleRules::CODE_PREFIX;
        $counts = [];
        foreach ($this->bundles->courses($id) as $course) {
            $prefix = \CattoLearning\Support\PositionBands::prefixOf((string) ($course['course_code'] ?? ''));
            if ($prefix !== '') $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
        }
        if ($counts === []) return BundleRules::CODE_PREFIX;
        $most = max($counts);
        foreach ($counts as $prefix => $count) if ($count === $most) return $prefix;
        return BundleRules::CODE_PREFIX;
    }

    /**
     * The bundle's code (owner, 2026-11-06: "Each bundle needs its own code, like a course code"): the one typed, tidied
     * and checked by a course code's rule, else a suggestion from the title. A code names one thing: no course and no
     * other bundle may have it, so a catalogue import can match a bundle by its code alone.
     */
    public function code(string $input, string $title, ?int $id = null): string
    {
        $taken = fn(string $code): bool => $this->bundles->codeTaken($code, $id) || $this->bundles->courseWithCode($code) !== null;
        try {
            $code = trim($input) === '' ? BundleRules::suggestCode($title, $taken, $this->prefixFromCourses($id)) : CourseCode::validate($input);
        } catch (\InvalidArgumentException $invalid) {
            throw new RuntimeException(str_replace('A course code is', 'A bundle code is', $invalid->getMessage()));
        }
        $course = $this->bundles->courseWithCode($code);
        if ($course !== null) throw new RuntimeException('The code ' . $code . ' is a course\'s: ' . $course['title'] . '. A code names one course or one bundle, so give the bundle a code of its own.');
        if ($this->bundles->codeTaken($code, $id)) throw new RuntimeException('Another bundle already has the code ' . $code . '. Each bundle has a code of its own.');
        return $code;
    }

    /**
     * The status a form chose, or null when it sent none.
     *
     * @param array<string,mixed> $input
     */
    private static function chosenStatus(array $input): ?string
    {
        if (!isset($input['status'])) return null;
        $status = is_scalar($input['status']) ? (string) $input['status'] : '';
        if (!in_array($status, BundleRules::STATUSES, true)) throw new RuntimeException('Choose draft, published or retired.');
        return $status;
    }

    /**
     * What a unique key refused: the code or the web address another bundle (or a course) took meanwhile.
     *
     * @param array<string,mixed> $fields
     */
    private function taken(array $fields, ?int $id): RuntimeException
    {
        $code = (string) $fields['code'];
        if ($this->bundles->codeTaken($code, $id) || $this->bundles->courseWithCode($code) !== null) {
            return new RuntimeException('The code ' . $code . ' is taken. Each bundle has a code of its own, never a course\'s.');
        }
        return self::slugTaken((string) $fields['slug']);
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
