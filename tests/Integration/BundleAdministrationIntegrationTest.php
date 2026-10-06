<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\{CurrentUser, PermissionCatalog};
use CattoLearning\Bundle\{BundleRepository, BundleService};
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Http\Controller\{AdminBundleController, AdminLookupController, BundleController};
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture, BundleFixture, DevelopmentFixture, InProcessPage, IntegrationContainer};
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};

/**
 * ADMIN bundle management and the public bundle pages on the real services and controllers:
 * creating and validating, composing and reordering with an audit trail, the publishing rules, the
 * offer, retiring, deleting only unused drafts, the list's search and filters, permissions, the
 * bundle lookup, and what the catalogue shows. Every write rolls back.
 */
#[Group('commerce')]
final class BundleAdministrationIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private BundleService $bundles;
    private BundleRepository $records;
    private CurrentUser $admin;
    private int $owner;
    private int $provider;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->bundles = IntegrationContainer::get()->get(BundleService::class);
        $this->records = new BundleRepository($this->db);
        $id = $this->fixture->createUser('Bundle administrator', 'bundle-admin-' . $this->fixture->suffix() . '@example.test');
        $this->admin = new CurrentUser($id, Uuid::v4(), 'bundle-admin@example.test', 'Bundle administrator', ['ADMIN'], (new PermissionCatalog())->keys(), Uuid::v4());
        $this->owner = $this->fixture->createUser('Bundle course owner');
        $this->provider = $this->fixture->createCompany($this->owner, 'Bundle admin provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.bundle-admin.example.test');
        $_SESSION['csrf'] = str_repeat('c', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['csrf'], $_SESSION['flash'], $_SESSION['bundle_draft']);
    }

    public function testAnAdministratorComposesPricesPublishesAndRetiresABundleWithAnAuditTrail(): void
    {
        $suffix = $this->fixture->suffix();
        $id = $this->bundles->create($this->admin, ['title' => 'Professional Office Skills ' . $suffix, 'short_description' => 'Four courses.', 'description_html' => '<p>Learn the office.</p>']);
        $bundle = $this->records->find($id) ?? [];
        self::assertSame(['draft', 'professional-office-skills-' . $suffix], [$bundle['status'], $bundle['slug']], 'A new bundle is a draft; its address is made from the title.');
        [$a, $b, $c] = [$this->course('A', 50000), $this->course('B', 70000), $this->course('C', 40000)];
        try {
            $this->bundles->setStatus($this->admin, $id, 'published');
            self::fail('A bundle needs two courses to be published.');
        } catch (RuntimeException $refused) {
            self::assertSame('Add at least two courses before publishing the bundle.', $refused->getMessage());
        }
        foreach ([$a, $b, $c] as $course) $this->bundles->addCourse($this->admin, $id, $course);
        try {
            $this->bundles->addCourse($this->admin, $id, $b);
            self::fail('A course is in a bundle once.');
        } catch (RuntimeException $refused) {
            self::assertStringEndsWith('is already in this bundle.', $refused->getMessage());
        }
        $this->db->executeStatement('SAVEPOINT duplicate');
        try {
            $this->db->executeStatement('INSERT INTO bundle_courses(bundle_id,course_id,position) VALUES (:b,:c,9)', ['b' => $id, 'c' => $b]);
            self::fail('The database refuses a duplicate course.');
        } catch (DbalException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT duplicate');
        }
        self::assertTrue($this->bundles->moveCourse($this->admin, $id, $c, 'up'));
        self::assertFalse($this->bundles->moveCourse($this->admin, $id, $a, 'up'), 'The first course cannot move up.');
        self::assertSame([$a, $c, $b], $this->courseIds($id), 'The order is kept as arranged.');
        self::assertSame([1, 2, 3], array_map(static fn(array $r): int => (int) $r['position'], $this->records->courses($id)));
        try {
            $this->bundles->setStatus($this->admin, $id, 'published');
            self::fail('A bundle needs a price to be published.');
        } catch (RuntimeException $refused) {
            self::assertSame('Set the bundle price before publishing it.', $refused->getMessage());
        }
        foreach (['0' => 'A bundle price must be greater than zero.', '12.345' => 'Enter the bundle price as an amount with no more than two decimal places.', 'free' => 'Enter the bundle price as an amount with no more than two decimal places.'] as $price => $message) {
            try {
                $this->bundles->saveOffer($this->admin, $id, ['price' => (string) $price, 'access_period_value' => '1', 'access_period_unit' => 'years', 'is_active' => '1']);
                self::fail($message);
            } catch (RuntimeException $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }
        $this->bundles->saveOffer($this->admin, $id, ['price' => '1200', 'access_period_value' => '1', 'access_period_unit' => 'years', 'is_active' => '1']);
        self::assertTrue($this->bundles->setStatus($this->admin, $id, 'published'));
        try {
            $this->bundles->removeCourse($this->admin, $id, $a);
            $this->bundles->removeCourse($this->admin, $id, $c);
            self::fail('A published bundle keeps at least two courses.');
        } catch (RuntimeException $refused) {
            self::assertSame('A published bundle needs at least two courses. Add another course first, or return the bundle to draft.', $refused->getMessage());
        }
        self::assertSame([$c, $b], $this->courseIds($id), 'Removing a course closes the gap.');
        $this->bundles->saveOffer($this->admin, $id, ['price' => '999.50', 'access_period_value' => '6', 'access_period_unit' => 'months', 'is_active' => '1']);
        self::assertTrue($this->bundles->setStatus($this->admin, $id, 'retired'));

        $audit = $this->audit($id);
        self::assertSame(['bundle.created', 'bundle.course_added', 'bundle.course_added', 'bundle.course_added', 'bundle.courses_reordered', 'bundle.offer_updated', 'bundle.status_changed', 'bundle.course_removed', 'bundle.offer_updated', 'bundle.retired'], array_column($audit, 'event_key'));
        self::assertEquals(['bundle_id' => $id, 'from' => ['price_minor' => 120000, 'access_period_seconds' => 31536000, 'on_sale' => true], 'to' => ['price_minor' => 99950, 'access_period_seconds' => 15552000, 'on_sale' => true], 'currency' => 'ZAR'], $audit[8]['metadata'], 'A price change records the old and new price, not the whole bundle.');
        self::assertEquals(['bundle_id' => $id, 'from' => 'published', 'to' => 'retired'], $audit[9]['metadata']);
    }

    public function testEveryMistakeInTheDetailsIsExplained(): void
    {
        $taken = BundleFixture::create($this->db, $this->owner, [], 1000, ['slug' => 'taken-' . $this->fixture->suffix(), 'status' => 'draft']);
        foreach ([
            'Give the bundle a title of at most 240 characters.' => ['title' => ''],
            'Use lower-case letters, digits and single hyphens for the web address.' => ['title' => 'Fine', 'slug' => 'Not a slug!'],
            'Another bundle already uses the web address ' . $taken['slug'] . '. Choose a different one.' => ['title' => 'Fine', 'slug' => $taken['slug']],
            'The cover must be SVG markup starting with <svg, or left empty.' => ['title' => 'Fine', 'cover_svg' => '<img src=x>'],
            'The end of the sales period must be after its start.' => ['title' => 'Fine', 'available_from' => '2026-11-02T00:00', 'available_until' => '2026-11-01T00:00'],
            'Enter "available from" as a date and time, or leave it empty.' => ['title' => 'Fine', 'available_from' => 'soon'],
        ] as $message => $input) {
            try {
                $this->bundles->create($this->admin, $input);
                self::fail($message);
            } catch (RuntimeException $mistake) {
                self::assertSame($message, $mistake->getMessage());
            }
        }
    }

    public function testOnlyAnUnusedDraftCanBeDeletedAndASoldBundleKeepsItsHistory(): void
    {
        $unused = BundleFixture::create($this->db, $this->owner, [$this->course('A', 1000), $this->course('B', 1000)], 1500, ['status' => 'draft']);
        $this->bundles->delete($this->admin, $unused['id']);
        self::assertNull($this->records->find($unused['id']));

        $sold = BundleFixture::create($this->db, $this->owner, [$this->course('C', 1000), $this->course('D', 1000)], 1500);
        $buyerId = $this->fixture->createUser('Bundle purchaser', 'bundle-purchaser-' . bin2hex(random_bytes(5)) . '@example.test');
        $buyer = new CurrentUser($buyerId, Uuid::v4(), 'purchaser@example.test', 'Purchaser', ['STUDENT'], ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START'], Uuid::v4());
        $orders = IntegrationContainer::get()->get(OrderService::class);
        $orders->changeBundle($buyer, $sold['offer']);
        $cart = $orders->cart($buyer);
        $orders->place($buyer, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person(), true);
        $this->bundles->setStatus($this->admin, $sold['id'], 'draft');
        try {
            $this->bundles->delete($this->admin, $sold['id']);
            self::fail('A sold bundle is kept.');
        } catch (RuntimeException $kept) {
            self::assertSame('This bundle has been sold, so it is kept for its order history. Retire it to stop selling it.', $kept->getMessage());
        }
        $this->db->executeStatement('SAVEPOINT sold');
        try {
            $this->db->executeStatement('DELETE FROM bundles WHERE id=:id', ['id' => $sold['id']]);
            self::fail('The database keeps a bundle an order line refers to.');
        } catch (DbalException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT sold');
        }
        $published = BundleFixture::create($this->db, $this->owner, [$this->course('E', 1000), $this->course('F', 1000)], 1500);
        try {
            $this->bundles->delete($this->admin, $published['id']);
            self::fail('Only a draft is deleted.');
        } catch (RuntimeException $refused) {
            self::assertSame('Only a draft bundle can be deleted. Return it to draft first, or retire it.', $refused->getMessage());
        }
    }

    public function testTheListAndEditorShowBundlesToThoseWhoMayManageThem(): void
    {
        $prefix = 'list-' . $this->fixture->suffix();
        $onSale = BundleFixture::create($this->db, $this->owner, [$this->course('A', 1000), $this->course('B', 1000)], 1500, ['slug' => $prefix . '-on', 'title' => 'Listed on sale']);
        BundleFixture::create($this->db, $this->owner, [], 1500, ['slug' => $prefix . '-draft', 'title' => 'Listed draft', 'status' => 'draft']);
        BundleFixture::create($this->db, $this->owner, [], 1500, ['slug' => $prefix . '-off', 'title' => 'Listed off sale'], 31536000, false);
        BundleFixture::create($this->db, $this->owner, [], 1500, ['slug' => $prefix . '-retired', 'title' => 'Listed retired', 'status' => 'retired']);
        foreach (['published' => 'Listed on sale', 'draft' => 'Listed draft', 'off_sale' => 'Listed off sale', 'retired' => 'Listed retired'] as $status => $title) {
            self::assertSame([$title], array_column($this->records->list(['search' => $prefix, 'status' => $status], 50, 0), 'title'), $status);
        }
        self::assertSame(4, $this->records->count(['search' => strtoupper($prefix)]));

        $page = InProcessPage::run($this->admin, AdminBundleController::class, 'index', [], null, ['bundles_q' => $prefix, 'status' => 'off_sale']);
        self::assertStringContainsString('Listed off sale', $page['body']);
        self::assertStringNotContainsString('Listed retired', $page['body']);
        $editor = InProcessPage::run($this->admin, AdminBundleController::class, 'show', ['id' => (string) $onSale['id']]);
        foreach (['action="/admin/bundles/' . $onSale['id'] . '/courses"', 'name="add_course_id"', '/move"', '/remove"', 'action="/admin/bundles/' . $onSale['id'] . '/offer"', 'Retire', 'Not sold yet'] as $shown) self::assertStringContainsString($shown, $editor['body']);

        $failed = InProcessPage::run($this->admin, AdminBundleController::class, 'update', ['id' => (string) $onSale['id']], ['csrf' => $_SESSION['csrf'], 'title' => 'Kept title', 'slug' => 'NOT VALID']);
        self::assertSame('/admin/bundles/' . $onSale['id'], $failed['location']);
        self::assertStringContainsString('value="NOT VALID"', InProcessPage::run($this->admin, AdminBundleController::class, 'show', ['id' => (string) $onSale['id']])['body'], 'A failed save keeps what was entered.');

        $learner = new CurrentUser($this->admin->id, Uuid::v4(), 'learner@example.test', 'Learner', ['STUDENT'], ['COURSE.MANAGEMENT.VIEW'], Uuid::v4());
        $viewer = new CurrentUser($this->admin->id, Uuid::v4(), 'viewer@example.test', 'Viewer', ['STUDENT'], ['BUNDLE.MANAGEMENT.VIEW'], Uuid::v4());
        foreach (['index' => [], 'create' => []] as $method => $attributes) {
            try {
                InProcessPage::run($learner, AdminBundleController::class, $method, $attributes);
                self::fail($method . ' needs the bundle permissions.');
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $readOnly = InProcessPage::run($viewer, AdminBundleController::class, 'show', ['id' => (string) $onSale['id']]);
        self::assertStringNotContainsString('/offer"', $readOnly['body'], 'Viewing is not managing.');
        foreach (['add' => fn() => $this->bundles->addCourse($viewer, $onSale['id'], 1), 'retire' => fn() => $this->bundles->setStatus($viewer, $onSale['id'], 'retired')] as $what => $attempt) {
            try {
                $attempt();
                self::fail($what);
            } catch (AccessDeniedHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        $lookup = InProcessPage::run($this->admin, AdminLookupController::class, 'search', ['type' => 'bundles'], null, ['q' => 'Listed on', 'target' => 'add_bundle_id']);
        self::assertStringContainsString('Listed on sale', $lookup['body'], 'Bundles can be searched in the lookup, for promotions.');
    }

    public function testTheCatalogueShowsWhatABundleIncludesWhatItCostsAndWhetherItCanBeBought(): void
    {
        [$a, $b, $c] = [$this->course('A', 50000), $this->course('B', 70000), $this->course('C', 40000)];
        $bundle = BundleFixture::create($this->db, $this->owner, [$a, $b, $c], 120000, ['title' => 'Catalogue Bundle']);
        $learnerId = $this->fixture->createUser('Bundle viewer', 'bundle-viewer-' . bin2hex(random_bytes(5)) . '@example.test');
        $this->fixture->createEnrolment($learnerId, $a, $this->owner);
        $learner = new CurrentUser($learnerId, Uuid::v4(), 'viewer@example.test', 'Viewer', ['STUDENT'], ['COMMERCE.CART.VIEW'], Uuid::v4());
        $page = InProcessPage::run($learner, BundleController::class, 'show', ['slug' => $bundle['slug']]);
        foreach (['Catalogue Bundle', 'Course A', 'Course B', 'Course C', 'cl-course-card', 'Individual course prices', 'You save', 'action="/cart/add-bundle"', 'You already have access to 1 of the 3 courses in this bundle.'] as $shown) {
            self::assertStringContainsString($shown, $page['body'], $shown);
        }
        foreach ([120000, 160000, 40000] as $amount) self::assertStringContainsString(htmlspecialchars(\CattoLearning\Support\Money::strictMinorUnits($amount, 'ZAR')->format(), ENT_QUOTES), $page['body']);
        self::assertStringContainsString('Catalogue Bundle', InProcessPage::run(null, BundleController::class, 'index')['body']);

        $this->bundles->setStatus($this->admin, $bundle['id'], 'retired');
        $retired = InProcessPage::run(null, BundleController::class, 'show', ['slug' => $bundle['slug']]);
        self::assertStringContainsString('This bundle is no longer sold.', $retired['body']);
        self::assertStringNotContainsString('action="/cart/add-bundle"', $retired['body']);
        $this->bundles->setStatus($this->admin, $bundle['id'], 'draft');
        try {
            InProcessPage::run(null, BundleController::class, 'show', ['slug' => $bundle['slug']]);
            self::fail('A draft is not public.');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    private function course(string $key, int $price): int
    {
        $id = $this->fixture->createCourse($this->owner, $this->provider, 'bundle-admin-' . $this->fixture->suffix() . '-' . strtolower($key), 'Course ' . $key, 'published');
        $this->fixture->createPriceVariant($id, $this->owner, 86400, $price, true);
        return $id;
    }

    /** @return list<int> */
    private function courseIds(int $bundleId): array
    {
        return array_map(static fn(array $row): int => (int) $row['course_id'], $this->records->courses($bundleId));
    }

    /** @return list<array{event_key:string,metadata:array<string,mixed>}> */
    private function audit(int $bundleId): array
    {
        return array_map(static fn(array $row): array => ['event_key' => (string) $row['event_key'], 'metadata' => json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR)],
            $this->db->fetchAllAssociative("SELECT event_key, metadata FROM audit_log WHERE event_key LIKE 'bundle.%' AND metadata->>'bundle_id'=:b ORDER BY id", ['b' => (string) $bundleId]));
    }
}
