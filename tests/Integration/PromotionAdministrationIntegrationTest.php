<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Auth\PermissionCatalog;
use CattoLearning\Commerce\Application\{OrderService,PromotionAdministrationService,PromotionService};
use CattoLearning\Commerce\Http\PromotionAdministrationController;
use CattoLearning\Commerce\Infrastructure\PromotionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,InProcessPage,IntegrationContainer,PromotionFixture};
use Doctrine\DBAL\Exception\{DriverException,ForeignKeyConstraintViolationException,UniqueConstraintViolationException};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * ADMIN promotion management on the real services and controller: creating, editing, activating and
 * deactivating; every validation message; codes unique whatever their case, down to the database;
 * deleting only what no order used; the list's search and status filter; the editor's usage and its
 * handling of a failed save; and refusal for anyone without the promotion permissions. Every write
 * rolls back.
 */
#[Group('commerce')]
final class PromotionAdministrationIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private PromotionAdministrationService $administration;
    private PromotionRepository $promotions;
    private CurrentUser $admin;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->administration = IntegrationContainer::get()->get(PromotionAdministrationService::class);
        $this->promotions = new PromotionRepository($this->db);
        $id = $this->fixture->createUser('Promotion administrator', 'promo-admin-' . $this->fixture->suffix() . '@example.test');
        $this->admin = new CurrentUser($id, Uuid::v4(), 'promo-admin@example.test', 'Promotion administrator', ['ADMIN'], (new PermissionCatalog())->keys(), Uuid::v4());
        $_SESSION['csrf'] = str_repeat('a', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['csrf'], $_SESSION['flash'], $_SESSION['promotion_draft'], $_SESSION['checkout']);
    }

    public function testAnAdministratorCreatesEditsActivatesAndDeactivatesAPromotion(): void
    {
        $code = $this->code('spring');
        $id = $this->administration->create($this->admin, $this->input(['code' => '  ' . $code . ' ', 'discount_value' => '12.5', 'starts_at' => '2026-11-01T00:00', 'ends_at' => '2026-12-01T00:00', 'maximum_total_uses' => '100', 'maximum_uses_per_customer' => '1']));
        $saved = $this->promotions->find($id);
        self::assertNotNull($saved);
        self::assertSame([strtoupper($code), 'percentage', 1250, null, 100, 1, true], [$saved->code, $saved->discountType, $saved->discountValue, $saved->currency, $saved->maximumTotalUses, $saved->maximumUsesPerCustomer, $saved->active], 'Stored in canonical form, the percentage in basis points; no currency without an amount.');
        $zone = PromotionAdministrationService::timezone();
        self::assertSame(['2026-11-01 00:00', '2026-12-01 00:00'], [$saved->startsAt?->setTimezone($zone)->format('Y-m-d H:i'), $saved->endsAt?->setTimezone($zone)->format('Y-m-d H:i')], 'Entered and kept in the platform time zone.');

        $course = $this->course();
        $this->administration->update($this->admin, $id, $this->input(['code' => $code, 'discount_type' => 'fixed_amount', 'discount_value' => '99.50', 'minimum_order' => '500', 'course_scope' => 'selected', 'add_course_id' => (string) $course]));
        $edited = $this->promotions->find($id);
        self::assertNotNull($edited);
        self::assertSame(['fixed_amount', 9950, 'ZAR', 50000, 'selected', [$course], null, null], [$edited->discountType, $edited->discountValue, $edited->currency, $edited->minimumOrderMinor, $edited->courseScope, $edited->courseIds, $edited->startsAt, $edited->endsAt]);
        $changed = $this->audit('promotion.updated', $id);
        self::assertSame(['discountType', 'discountValue', 'currency', 'minimumOrderMinor', 'maximumTotalUses', 'maximumUsesPerCustomer', 'courseScope', 'courseIds', 'startsAt', 'endsAt'], $changed[0]['changed']);

        self::assertTrue($this->administration->setActive($this->admin, $id, false));
        self::assertFalse($this->administration->setActive($this->admin, $id, false), 'Deactivating twice changes nothing.');
        self::assertTrue($this->administration->setActive($this->admin, $id, true));
        self::assertSame([1, 1, 1], [count($this->audit('promotion.created', $id)), count($this->audit('promotion.deactivated', $id)), count($this->audit('promotion.activated', $id))]);
    }

    public function testEveryMistakeInTheEditorIsExplained(): void
    {
        $cases = [
            'Use 3 to 40 letters, digits, hyphens or underscores for the code, starting with a letter or digit.' => ['code' => 'no spaces'],
            'Give the promotion a name of at most 120 characters.' => ['name' => ''],
            'Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.' => ['discount_value' => '0'],
            'Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.' . ' ' => ['discount_value' => '100.01'],
            'Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.' . '  ' => ['discount_value' => '-5'],
            'Enter an amount off greater than zero, such as 100 or 99.50.' => ['discount_type' => 'fixed_amount', 'discount_value' => '0.00'],
            'Enter an amount off greater than zero, such as 100 or 99.50.' . ' ' => ['discount_type' => 'fixed_amount', 'discount_value' => '1.234'],
            'Enter the minimum spend in rands and cents, such as 1000 or 999.99.' => ['minimum_order' => 'lots'],
            'Leave the maximum total uses empty for no limit, or enter a whole number from 1 to 1,000,000.' => ['maximum_total_uses' => '0'],
            'The end must be after the start.' => ['starts_at' => '2026-11-02T00:00', 'ends_at' => '2026-11-01T00:00'],
            'Enter the start as a date and time, or leave it empty.' => ['starts_at' => 'tomorrow'],
            'Choose at least one course, or apply the promotion to all courses or to none.' => ['course_scope' => 'selected'],
            'Choose at least one bundle, or apply the promotion to all bundles or to none.' => ['bundle_scope' => 'selected'],
            'Apply the promotion to courses, to bundles, or to both.' => ['course_scope' => 'none', 'bundle_scope' => 'none'],
            'One of the chosen bundles no longer exists. Review the selection.' => ['bundle_scope' => 'selected', 'bundle_ids' => ['999999999']],
            'One of the chosen courses no longer exists. Review the selection.' => ['course_scope' => 'selected', 'course_ids' => ['999999999']],
            'Choose a percentage or a fixed amount off.' => ['discount_type' => 'free'],
        ];
        foreach ($cases as $message => $overrides) {
            try {
                $this->administration->create($this->admin, $this->input($overrides));
                self::fail('Expected: ' . $message);
            } catch (RuntimeException $mistake) {
                self::assertSame(trim($message), $mistake->getMessage());
            }
        }
        self::assertSame(100, $this->promotions->find($this->administration->create($this->admin, $this->input(['discount_value' => '100'])))?->discountValue / 100, '100% is allowed.');
    }

    public function testCodesAreUniqueWhateverTheirCaseDownToTheDatabase(): void
    {
        $code = $this->code('UNIQUE');
        $first = $this->administration->create($this->admin, $this->input(['code' => $code]));
        try {
            $this->administration->create($this->admin, $this->input(['code' => strtolower($code)]));
            self::fail('A code differing only in case is the same code.');
        } catch (RuntimeException $taken) {
            self::assertSame('Another promotion already uses the code ' . $code . '. Codes are not case-sensitive, so choose a different code.', $taken->getMessage());
        }
        $other = $this->administration->create($this->admin, $this->input(['code' => $code . 'B']));
        try {
            $this->administration->update($this->admin, $other, $this->input(['code' => ucfirst(strtolower($code))]));
            self::fail('Renaming onto another code must be refused.');
        } catch (RuntimeException $taken) {
            self::assertStringStartsWith('Another promotion already uses the code', $taken->getMessage());
        }
        $this->administration->update($this->admin, $first, $this->input(['code' => strtolower($code), 'name' => 'Same code, new name']));
        self::assertSame($code, $this->promotions->find($first)?->code, 'A promotion keeps its own code.');

        $this->db->executeStatement('SAVEPOINT duplicate');
        try {
            PromotionFixture::create($this->db, $this->admin->id, ['code' => $code]);
            self::fail('The database refuses a duplicate code.');
        } catch (UniqueConstraintViolationException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT duplicate');
        }
        $this->db->executeStatement('SAVEPOINT lower_case');
        try {
            PromotionFixture::create($this->db, $this->admin->id, ['code' => strtolower($code) . 'x']);
            self::fail('The database stores codes in canonical form only.');
        } catch (DriverException $refused) {
            self::assertStringContainsString('promotions_code_check', $refused->getMessage());
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT lower_case');
        }
    }

    public function testOnlyAnUnusedPromotionCanBeDeletedAndAUsedOneKeepsItsHistory(): void
    {
        $unused = $this->administration->create($this->admin, $this->input());
        $this->administration->delete($this->admin, $unused);
        self::assertNull($this->promotions->find($unused));
        self::assertCount(1, $this->audit('promotion.deleted', $unused));

        $code = $this->code('USED');
        $used = $this->administration->create($this->admin, $this->input(['code' => $code]));
        $orderId = $this->placeWith($code);
        try {
            $this->administration->delete($this->admin, $used);
            self::fail('A used promotion is kept.');
        } catch (RuntimeException $kept) {
            self::assertSame('Orders were placed with this promotion, so it is kept for their history. Deactivate it instead.', $kept->getMessage());
        }
        $this->db->executeStatement('SAVEPOINT used');
        try {
            $this->db->executeStatement('DELETE FROM promotions WHERE id=:id', ['id' => $used]);
            self::fail('The database keeps a promotion an order refers to.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT used');
        }
        $this->administration->setActive($this->admin, $used, false);
        $this->administration->update($this->admin, $used, $this->input(['code' => $code . 'NEW', 'discount_value' => '90']));
        $order = $this->db->fetchAssociative('SELECT * FROM commerce_orders WHERE id=:id', ['id' => $orderId]) ?: [];
        self::assertSame([$used, 1235], [(int) $order['promotion_id'], (int) $order['discount_minor']]);
        self::assertSame($code, json_decode((string) $order['snapshot'], true)['promotion']['code'], 'The order keeps the code it was placed with.');
    }

    public function testTheListSearchesAndFiltersByStatus(): void
    {
        $now = new \DateTimeImmutable();
        $prefix = 'L' . strtoupper(substr($this->fixture->suffix(), 0, 6));
        $codes = [
            'active' => PromotionFixture::create($this->db, $this->admin->id, ['code' => $prefix . 'ACTIVE', 'name' => 'Live list offer']),
            'inactive' => PromotionFixture::create($this->db, $this->admin->id, ['code' => $prefix . 'OFF', 'active' => false]),
            'scheduled' => PromotionFixture::create($this->db, $this->admin->id, ['code' => $prefix . 'SOON', 'starts_at' => $now->modify('+1 day')->format(DATE_ATOM)]),
            'ended' => PromotionFixture::create($this->db, $this->admin->id, ['code' => $prefix . 'OVER', 'starts_at' => $now->modify('-2 days')->format(DATE_ATOM), 'ends_at' => $now->modify('-1 day')->format(DATE_ATOM)]),
        ];
        $at = $now->format(DATE_ATOM);
        foreach ($codes as $status => $id) {
            $listed = array_map(static fn(array $row): int => (int) $row['id'], $this->promotions->list(['search' => $prefix, 'status' => $status], $at, 50, 0));
            self::assertSame([$id], $listed, $status);
        }
        self::assertSame(4, $this->promotions->count(['search' => strtolower($prefix)], $at), 'Search is case-insensitive.');
        self::assertSame([$codes['active']], array_map(static fn(array $row): int => (int) $row['id'], $this->promotions->list(['search' => 'Live list'], $at, 50, 0)), 'Names are searched as well as codes.');

        $page = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'index', [], null, ['promotions_q' => $prefix, 'status' => 'scheduled']);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString($prefix . 'SOON', $page['body']);
        self::assertStringNotContainsString($prefix . 'ACTIVE', $page['body']);
        foreach (['Code', 'Discount', 'Validity', 'Status', 'Uses', 'Per customer', 'Applies to', 'Scheduled', 'href="/admin/promotions/new"'] as $shown) self::assertStringContainsString($shown, $page['body']);
    }

    public function testTheEditorShowsUsageAndAFailedSaveKeepsWhatWasEntered(): void
    {
        $code = $this->code('SHOW');
        $id = $this->administration->create($this->admin, $this->input(['code' => $code, 'maximum_total_uses' => '10']));
        $orderId = $this->placeWith($code);
        $page = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'show', ['id' => (string) $id]);
        self::assertSame(200, $page['status']);
        foreach (['CL-' . str_pad((string) $orderId, 8, '0', STR_PAD_LEFT), 'Not paid yet', 'Orders were placed with this promotion', 'Unpaid orders', 'Limit 10 uses', 'action="/admin/promotions/' . $id . '/status"'] as $shown) {
            self::assertStringContainsString($shown, $page['body']);
        }
        self::assertStringNotContainsString('/delete"', $page['body'], 'No delete for a used promotion.');

        $failed = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'update', ['id' => (string) $id], ['csrf' => $_SESSION['csrf']] + $this->input(['code' => $code, 'name' => 'Corrected name', 'discount_value' => '150']));
        self::assertSame('/admin/promotions/' . $id, $failed['location']);
        self::assertSame('Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.', end($_SESSION['flash'])['message']);
        $again = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'show', ['id' => (string) $id]);
        self::assertStringContainsString('value="Corrected name"', $again['body'], 'The entered values are shown again to correct.');
        self::assertStringContainsString('value="150"', $again['body']);
        self::assertSame('Test promotion', $this->promotions->find($id)?->name, 'Nothing was saved.');

        $created = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'store', [], ['csrf' => $_SESSION['csrf']] + $this->input(['code' => $this->code('NEW')]));
        self::assertMatchesRegularExpression('#^/admin/promotions/\d+$#', (string) $created['location']);
        $new = InProcessPage::run($this->admin, PromotionAdministrationController::class, 'create');
        self::assertStringContainsString('Create promotion', $new['body']);
        self::assertStringContainsString('name="add_course_id"', $new['body'], 'Selected courses are chosen with the course lookup.');
    }

    public function testOnlyPromotionPermissionsReachTheScreens(): void
    {
        $id = $this->administration->create($this->admin, $this->input());
        $learner = new CurrentUser($this->admin->id, Uuid::v4(), 'learner@example.test', 'Learner', ['STUDENT'], ['COMMERCE.CHECKOUT.START', 'PLATFORM.ORDER.VIEW'], Uuid::v4());
        $viewer = new CurrentUser($this->admin->id, Uuid::v4(), 'viewer@example.test', 'Viewer', ['STUDENT'], ['PLATFORM.PROMOTION.VIEW'], Uuid::v4());
        foreach (['index' => [], 'show' => ['id' => (string) $id], 'create' => []] as $method => $attributes) {
            try {
                InProcessPage::run($learner, PromotionAdministrationController::class, $method, $attributes);
                self::fail($method . ' must be refused without the promotion permissions.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $readOnly = InProcessPage::run($viewer, PromotionAdministrationController::class, 'show', ['id' => (string) $id]);
        self::assertSame(200, $readOnly['status']);
        self::assertStringNotContainsString('<form action="/admin/promotions/' . $id . '"', $readOnly['body'], 'Viewing is not editing.');
        foreach (['create' => fn() => $this->administration->create($viewer, $this->input()), 'deactivate' => fn() => $this->administration->setActive($viewer, $id, false), 'delete' => fn() => $this->administration->delete($viewer, $id)] as $what => $attempt) {
            try {
                $attempt();
                self::fail('Viewing must not allow ' . $what . '.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + ['code' => $this->code('P'), 'name' => 'Test promotion', 'description' => '', 'active' => '1', 'discount_type' => 'percentage', 'discount_value' => '10',
            'minimum_order' => '', 'maximum_total_uses' => '', 'maximum_uses_per_customer' => '', 'starts_at' => '', 'ends_at' => '', 'course_scope' => 'all', 'bundle_scope' => 'none'];
    }

    private function code(string $prefix): string
    {
        return $prefix . strtoupper(bin2hex(random_bytes(4)));
    }

    private function course(): int
    {
        $owner = $this->fixture->createUser('Promotion course owner');
        $company = $this->fixture->createCompany($owner, 'Promotion admin provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.promo-admin.example.test');
        return $this->fixture->createCourse($owner, $company, 'promo-admin-' . $this->fixture->suffix(), 'Promotion admin course', 'published');
    }

    /** An unpaid order for a R123.45 course placed with the code by a new purchaser; returns its id. */
    private function placeWith(string $code): int
    {
        $course = $this->course();
        $owner = (int) $this->db->fetchOne('SELECT owner_user_id FROM courses WHERE id=:id', ['id' => $course]);
        $variant = $this->fixture->createPriceVariant($course, $owner, 86400, 12345, true);
        $buyerId = $this->fixture->createUser('Promotion purchaser', 'promo-purchaser-' . bin2hex(random_bytes(5)) . '@example.test');
        $buyer = new CurrentUser($buyerId, Uuid::v4(), 'purchaser@example.test', 'Purchaser', ['STUDENT'], ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START'], Uuid::v4());
        $orders = IntegrationContainer::get()->get(OrderService::class);
        $orders->changeCart($buyer, $variant);
        IntegrationContainer::get()->get(PromotionService::class)->apply($buyer, $code);
        $cart = $orders->cart($buyer);
        return $orders->place($buyer, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person(), true);
    }

    /** @return list<array<string,mixed>> the metadata of the promotion's audit events of this kind */
    private function audit(string $event, int $promotionId): array
    {
        return array_map(static fn(string $metadata): array => json_decode($metadata, true, 512, JSON_THROW_ON_ERROR),
            $this->db->fetchFirstColumn("SELECT metadata FROM audit_log WHERE event_key=:e AND metadata->>'promotion_id'=:p ORDER BY id", ['e' => $event, 'p' => (string) $promotionId]));
    }
}
