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
        self::assertEquals(['bundle_id' => $id, 'from' => ['price_minor' => 120000, 'was_price_minor' => null, 'access_period_seconds' => 31536000, 'on_sale' => true], 'to' => ['price_minor' => 99950, 'was_price_minor' => null, 'access_period_seconds' => 15552000, 'on_sale' => true], 'currency' => 'ZAR'], $audit[8]['metadata'], 'A price change records the old and new price, not the whole bundle.');
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

    public function testEachBundleHasACodeNoCourseOrOtherBundleHas(): void
    {
        // Owner, 2026-11-06: "Each bundle needs its own code, like a course code".
        $suffix = strtoupper(substr($this->fixture->suffix(), 0, 6));
        $id = $this->bundles->create($this->admin, ['title' => 'Garden Design Set ' . $suffix, 'code' => 'bdl gds ' . $suffix]);
        self::assertSame('BDL-GDS-' . $suffix, $this->records->find($id)['code'] ?? null, 'Typed as a person types it, kept as a code is written.');
        $suggested = $this->bundles->create($this->admin, ['title' => 'Pottery Course Bundle ' . $suffix]);
        self::assertMatchesRegularExpression('/^BDL-PCB/', (string) ($this->records->find($suggested)['code'] ?? ''), 'Left empty, one is made from the title.');
        $course = $this->course('Coded', 1000);
        $courseCode = (string) $this->db->fetchOne('SELECT code FROM courses WHERE id = :id', ['id' => $course]);
        foreach ([
            'The code ' . $courseCode . ' is a course\'s: Course Coded. A code names one course or one bundle, so give the bundle a code of its own.' => $courseCode,
            'Another bundle already has the code BDL-GDS-' . $suffix . '. Each bundle has a code of its own.' => 'BDL-GDS-' . $suffix,
            'A bundle code is capital letters and digits, in parts joined by hyphens, with at least one letter, such as GDN-101 (2 to 20 characters). "BDL-!" is not one.' => 'BDL--!',
        ] as $message => $code) {
            try {
                $this->bundles->updateDetails($this->admin, $suggested, ['title' => 'Pottery Course Bundle ' . $suffix, 'code' => $code]);
                self::fail($message);
            } catch (RuntimeException $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }
        // And the other way: no course may take a bundle's code, by its form or by the database.
        try {
            IntegrationContainer::get()->get(\CattoLearning\Course\CourseService::class)->courseCode('BDL-GDS-' . $suffix, 'A course', $course);
            self::fail('A course may not take a bundle\'s code.');
        } catch (\InvalidArgumentException $refused) {
            self::assertStringStartsWith('The code BDL-GDS-' . $suffix . ' is a bundle\'s: Garden Design Set', $refused->getMessage());
        }
        $this->db->executeStatement('SAVEPOINT code');
        try {
            $this->db->executeStatement('UPDATE courses SET code = :code WHERE id = :id', ['code' => 'BDL-GDS-' . $suffix, 'id' => $course]);
            self::fail('The database keeps a code to one course or one bundle.');
        } catch (DbalException) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT code');
        }
        self::assertSame(1, $this->records->count(['search' => 'gds-' . $suffix]), 'The list finds a bundle by its code.');
    }

    public function testTheStatusIsChosenOnTheBundleFormByTheSameChecks(): void
    {
        // Owner, 2026-11-06: "On the bundle form (new or edit), I can't set the status ... use radio buttons".
        $suffix = $this->fixture->suffix();
        $form = InProcessPage::run($this->admin, AdminBundleController::class, 'create');
        foreach (['name="status" value="draft" checked', 'name="status" value="published"', 'name="status" value="retired"', 'Only administrators see it.', 'In the catalogue.', 'Off the catalogue, and kept for its orders.', 'id="bundle-code"'] as $shown) {
            self::assertStringContainsString($shown, $form['body'], $shown);
        }
        $refused = null;
        $id = $this->bundles->create($this->admin, ['title' => 'Status Bundle ' . $suffix, 'status' => 'published'], $refused);
        self::assertSame(['draft', 'Add at least two courses before publishing the bundle.'], [$this->records->find($id)['status'] ?? null, $refused], 'A new bundle is made a draft when the checks refuse what was chosen, and says why.');
        $this->bundles->addCourse($this->admin, $id, $this->course('S1', 1000));
        $this->bundles->addCourse($this->admin, $id, $this->course('S2', 1000));
        try {
            $this->bundles->updateDetails($this->admin, $id, ['title' => 'Renamed ' . $suffix, 'status' => 'published']);
            self::fail('Publishing still needs a price.');
        } catch (RuntimeException $notYet) {
            self::assertSame('Set the bundle price before publishing it.', $notYet->getMessage());
        }
        self::assertSame(['Status Bundle ' . $suffix, 'draft'], [$this->records->find($id)['title'], $this->records->find($id)['status']], 'Refused, nothing is saved: the details and the status go together.');
        $this->bundles->saveOffer($this->admin, $id, ['price' => '1500', 'access_period_value' => '1', 'access_period_unit' => 'years', 'is_active' => '1']);
        $code = (string) $this->records->find($id)['code'];
        $saved = InProcessPage::run($this->admin, AdminBundleController::class, 'update', ['code' => $code], ['csrf' => $_SESSION['csrf'], 'title' => 'Renamed ' . $suffix, 'code' => $code, 'status' => 'published']);
        self::assertSame('/admin/bundles/' . $code, $saved['location'], 'A bundle\'s page is at its code.');
        self::assertSame(['Renamed ' . $suffix, 'published'], [$this->records->find($id)['title'], $this->records->find($id)['status']]);
        $editor = InProcessPage::run($this->admin, AdminBundleController::class, 'show', ['code' => $code]);
        self::assertStringContainsString('name="status" value="published" checked', $editor['body'], 'The edit form shows the status chosen.');
        self::assertStringNotContainsString('/status"', $editor['body'], 'No second way to change it.');
    }

    public function testARowOfTheListIsSavedInPlaceTogetherOrNotAtAll(): void
    {
        // Owner, 2026-11-06: "In the bundle list, it must be editable, just like courses are. these fields must be editable:
        // bundle code, title, status, price, start & end dates, access period".
        $suffix = strtoupper(substr($this->fixture->suffix(), 0, 6));
        $bundle = BundleFixture::create($this->db, $this->owner, [$this->course('R1', 1000), $this->course('R2', 1000)], 150000, ['title' => 'Row Bundle ' . $suffix, 'status' => 'draft']);
        $row = ['csrf' => $_SESSION['csrf'], 'code' => 'BDL-ROW-' . $suffix, 'title' => 'Row Bundle Plus ' . $suffix, 'status' => 'published', 'price' => '1250', 'was_price' => '1800',
            'available_from' => '2026-12-01T09:30', 'available_until' => '2027-01-31T17:00', 'access_period_value' => '18', 'access_period_unit' => 'months', 'view' => 'list', 'return' => '/admin/bundles?bundles_q=row'];
        $_SERVER['HTTP_HX_REQUEST'] = 'true';
        try {
            $saved = InProcessPage::run($this->admin, AdminBundleController::class, 'saveRow', ['code' => $bundle['code']], $row);
            // The row's code is now the one typed, and its addresses follow it.
            $code = 'BDL-ROW-' . $suffix;
            self::assertStringContainsString('id="bundle-' . $code . '"', $saved['body'], 'The row is anchored by its code.');
            self::assertStringContainsString('/admin/bundles/' . $code . '/row', $saved['body']);
            self::assertStringContainsString('data-bundle-saved', $saved['body'], 'The row comes back as it now is.');
            $money = static fn(int $minor): string => htmlspecialchars(\CattoLearning\Support\Money::strictMinorUnits($minor, 'ZAR')->format(), ENT_QUOTES);
            foreach (['BDL-ROW-' . $suffix, 'Row Bundle Plus ' . $suffix, $money(125000), $money(180000), '1 Dec 2026 09:30', '31 Jan 2027 17:00', '18 months'] as $shown) self::assertStringContainsString($shown, $saved['body'], $shown);
            $now = $this->records->find($bundle['id']) ?? [];
            self::assertSame(['published', 125000, 180000, 545 * 86400, true], [$now['status'], (int) $now['price_minor_units'], (int) $now['was_price_minor_units'], (int) $now['access_period_seconds'], (bool) $now['offer_active']],
                'Its status, price, was price and access saved, and whether it is on sale kept.');
            self::assertSame($bundle['slug'], $now['slug'], 'Its web address is the bundle form\'s to change.');

            $refused = InProcessPage::run($this->admin, AdminBundleController::class, 'saveRow', ['code' => $code], ['title' => 'Never saved', 'price' => 'lots'] + $row);
            self::assertSame(422, $refused['status']);
            self::assertStringContainsString('Enter the bundle price as an amount with no more than two decimal places.', $refused['body']);
            self::assertStringContainsString('value="Never saved"', $refused['body'], 'What was typed stays.');
            self::assertSame('Row Bundle Plus ' . $suffix, $this->records->find($bundle['id'])['title'], 'Nothing is saved when any of it is refused.');

            $edit = InProcessPage::run($this->admin, AdminBundleController::class, 'row', ['code' => $code], null, ['mode' => 'edit', 'view' => 'grid']);
            self::assertStringContainsString('class="cl-course-quick-card"', $edit['body'], 'A card opens as its form in the grid.');
        } finally {
            unset($_SERVER['HTTP_HX_REQUEST']);
        }
        $code = 'BDL-ROW-' . $suffix;
        $plain = InProcessPage::run($this->admin, AdminBundleController::class, 'saveRow', ['code' => $code], ['title' => 'Plain ' . $suffix] + $row);
        self::assertSame('/admin/bundles?bundles_q=row#bundle-' . $code, $plain['location'], 'Without JavaScript the list comes back.');
        $list = InProcessPage::run($this->admin, AdminBundleController::class, 'index', [], null, ['bundles_q' => 'row-' . strtolower($suffix), 'edit' => $code]);
        self::assertStringContainsString('id="bundle-quick-' . $bundle['id'] . '"', $list['body'], 'edit= opens the row as its form.');
        $viewer = new CurrentUser($this->admin->id, Uuid::v4(), 'viewer@example.test', 'Viewer', ['STUDENT'], ['BUNDLE.MANAGEMENT.VIEW'], Uuid::v4());
        self::assertStringNotContainsString('/row?mode=edit', InProcessPage::run($viewer, AdminBundleController::class, 'index', [], null, ['bundles_q' => 'row-' . strtolower($suffix)])['body'], 'No pencil without the bundle permission.');
        $this->expectException(AccessDeniedHttpException::class);
        InProcessPage::run($viewer, AdminBundleController::class, 'saveRow', ['code' => $code], $row);
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
        $editor = InProcessPage::run($this->admin, AdminBundleController::class, 'show', ['code' => $onSale['code']]);
        foreach (['action="/admin/bundles/' . $onSale['code'] . '/courses"', 'name="add_course_id"', '/move"', '/remove"', 'action="/admin/bundles/' . $onSale['code'] . '/offer"', 'Retire', 'Not sold yet'] as $shown) self::assertStringContainsString($shown, $editor['body']);

        $failed = InProcessPage::run($this->admin, AdminBundleController::class, 'update', ['code' => $onSale['code']], ['csrf' => $_SESSION['csrf'], 'title' => 'Kept title', 'slug' => 'NOT VALID']);
        self::assertSame('/admin/bundles/' . $onSale['code'], $failed['location']);
        self::assertStringContainsString('value="NOT VALID"', InProcessPage::run($this->admin, AdminBundleController::class, 'show', ['code' => $onSale['code']])['body'], 'A failed save keeps what was entered.');

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
        $readOnly = InProcessPage::run($viewer, AdminBundleController::class, 'show', ['code' => $onSale['code']]);
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
