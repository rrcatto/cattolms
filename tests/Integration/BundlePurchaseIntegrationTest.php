<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\{AnalyticsEventRecorder, AnalyticsEventRepository};
use CattoLearning\Auth\{AuthService, CurrentUser, PermissionCatalog};
use CattoLearning\Bundle\BundleService;
use CattoLearning\Commerce\Application\{AccessService,BillingProfileService,CartService,CheckoutService,CompanyCreditFulfilment,FulfilmentService,OrderService,PaymentService,PromotionService,RefundAdministrationService};
use CattoLearning\Commerce\Domain\PaymentResult;
use CattoLearning\Commerce\Http\{CommerceController,PaymentAdministrationController};
use CattoLearning\Commerce\Infrastructure\{CommerceRepository,InvoicePdfRenderer};
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\{CourseRepository, LearningService};
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,Database,TransactionManager};
use CattoLearning\Support\{Money,Uuid};
use CattoLearning\Tests\Support\{BillingFixture,BundleFixture,DevelopmentFixture,InProcessPage,IntegrationContainer,PromotionFixture};
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Buying course bundles end to end on this test's own connection: one bundle line that grants every
 * course as an ordinary entitlement with its provenance; fulfilment that never grants twice; orders
 * that keep the composition and price they were sold with; courses the learner already has left as
 * they are; overlapping bundles; refunds bounded by what was paid that revoke only what the bundle
 * granted; the cart's coverage and availability rules; promotions judged by product type; the
 * documents; analytics; and never a bundle on a company order. Every write rolls back.
 */
#[Group('commerce')]
final class BundlePurchaseIntegrationTest extends TestCase
{
    private const PERMISSIONS = ['COMMERCE.CART.VIEW','COMMERCE.CART.MANAGE','COMMERCE.CHECKOUT.START','COMMERCE.ORDER.VIEW','LEARNING.COURSE.START','ACCOUNT.PROFILE.EDIT'];

    private Database $db;
    private DevelopmentFixture $fixture;
    private MockClock $clock;
    private CommerceRepository $records;
    private OrderService $orders;
    private PaymentService $payments;
    private FulfilmentService $fulfilment;
    private PromotionService $promotions;
    private BillingProfileService $billing;
    private int $owner;
    private int $provider;
    /** @var array<string,array{id:int,variant:int,slug:string,title:string}> */
    private array $course = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->clock = new MockClock(new \DateTimeImmutable());
        $container = IntegrationContainer::get();
        $this->records = new CommerceRepository($this->db);
        $tx = new TransactionManager($this->db);
        $transitions = $container->get(TransitionService::class);
        $this->promotions = PromotionFixture::service($this->db, $this->clock);
        $this->orders = new OrderService($this->records, $tx, new CommercePolicy(dirname(__DIR__, 2)), $transitions, $this->clock, $this->promotions);
        $access = new AccessService($this->records, $tx, $transitions, $this->clock);
        $companyCredits = new CompanyCreditFulfilment($this->records, $container->get(AdministrationRepository::class), $container->get(CourseRepository::class), $this->clock);
        $this->fulfilment = new FulfilmentService($this->records, $transitions, $access, $tx, $this->orders, $this->clock, $companyCredits, $this->analytics(), $this->promotions);
        $this->payments = new PaymentService($this->records, $tx, $this->orders, new OmnipayPaymentGatewayAdapter('test', $this->clock), $this->fulfilment, $transitions, $this->clock);
        $this->billing = BillingFixture::service($this->db, $this->clock);
        $this->owner = $this->fixture->createUser('Bundle course owner');
        $this->provider = $this->fixture->createCompany($this->owner, 'Bundle provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.bundle.example.test');
        foreach (['A' => 50000, 'B' => 70000, 'C' => 40000, 'D' => 30000] as $key => $price) $this->course[$key] = $this->paidCourse('Course ' . $key, $price);
        $_SESSION['csrf'] = str_repeat('b', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['checkout'], $_SESSION['csrf'], $_SESSION['flash'], $_SESSION['guest_cart'], $_SESSION['guest_bundles']);
    }

    public function testABundleIsSoldAsOneLineAndGrantsEveryCourseOnce(): void
    {
        $bundle = $this->bundle(['A', 'B', 'C'], 120000, 'Office Skills Bundle');
        $buyer = $this->buyer();
        $orderId = $this->buy($buyer, [$bundle['offer']]);
        $order = $this->records->order($orderId);
        self::assertSame(['fulfilled', 120000], [$order['state'], (int) $order['total_minor']]);
        $items = $this->records->items($orderId);
        self::assertCount(1, $items, 'The bundle is one commercial line, not three course sales.');
        $line = $items[0];
        self::assertSame(['bundle', null, null, $bundle['id'], 120000, 0], [$line['product_type'], $line['course_id'], $line['variant_id'], (int) $line['bundle_id'], (int) $line['amount_minor'], (int) $line['discount_minor']]);
        $snapshot = CommerceRepository::decode((string) $line['snapshot']);
        self::assertSame(['bundle', $bundle['id'], 'Office Skills Bundle', 31536000, 120000], [$snapshot['fulfilment_type'], $snapshot['bundle_id'], $snapshot['bundle_title'], $snapshot['access_period_seconds'], $snapshot['unit_price_minor']]);
        self::assertSame($this->ids(['A', 'B', 'C']), array_column($snapshot['courses'], 'course_id'), 'The composition sold, in order.');

        $grants = $this->grants((int) $line['id']);
        self::assertSame(array_fill_keys($this->ids(['A', 'B', 'C']), false), array_map('boolval', array_column($grants, 'shared_at_grant', 'course_id')), 'No other source held any of them.');
        self::assertCount(3, array_unique(array_column($grants, 'entitlement_id')), 'Each course has its own bundle source.');
        $entitlements = $this->db->fetchAllAssociative("SELECT e.source, e.state, e.order_item_id, ce.course_id, ce.source_type, ce.access_period_seconds FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE e.order_item_id=:item ORDER BY ce.course_id", ['item' => $line['id']]);
        self::assertCount(3, $entitlements);
        foreach ($entitlements as $entitlement) {
            self::assertSame(['bundle', 'awaiting_activation', (int) $line['id'], 'bundle', 31536000], [$entitlement['source'], $entitlement['state'], (int) $entitlement['order_item_id'], $entitlement['source_type'], (int) $entitlement['access_period_seconds']], 'Bundle → order → order item → course provenance.');
        }
        $learning = IntegrationContainer::get()->get(LearningService::class);
        foreach (['A', 'B', 'C'] as $key) self::assertSame($this->course[$key]['id'], (int) $learning->courseHome($buyer->id, $this->course[$key]['slug'])['id'], 'Course ' . $key . ' opens like any course bought on its own.');
        self::assertEqualsCanonicalizing($this->ids(['A', 'B', 'C']), array_map(static fn(array $row): int => (int) $row['course_id'], $learning->library($buyer->id)));

        $purchased = $this->events('bundle_purchased', $buyer->id);
        self::assertCount(1, $purchased);
        self::assertEquals(['bundle_id' => $bundle['id'], 'amount_minor' => 120000, 'discount_minor' => 0, 'currency' => 'ZAR', 'course_count' => 3, 'courses_granted' => 3, 'courses_already_held' => 0, 'access_period_seconds' => 31536000], $purchased[0]['metadata']);
        self::assertSame([], $this->events('course_purchased', $buyer->id), 'The contained courses are not recorded as course sales.');

        // A replayed confirmation, and fulfilment run again on the paid order, grant nothing twice.
        $payment = $this->records->payments($orderId)[0];
        $this->payments->confirm('dummy', new PaymentResult((string) $payment['id'], 'paid', Money::strictMinorUnits(120000, 'ZAR'), (string) $payment['provider_reference']));
        self::assertTrue($this->fulfilment->fulfil(['state' => 'paid'] + $order));
        self::assertCount(3, $this->grants((int) $line['id']));
        self::assertSame(3, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements WHERE order_item_id=:item', ['item' => $line['id']]));
        self::assertSame(3, (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_enrolments WHERE user_id=:u AND status IN ('assigned','active','completed')", ['u' => $buyer->id]));
        self::assertCount(1, $this->events('bundle_purchased', $buyer->id));

        foreach ($this->documentsHtml($orderId) as $kind => $html) {
            self::assertStringContainsString('Bundle: Office Skills Bundle', $html, $kind);
            self::assertStringContainsString('Includes: Course A, Course B, Course C', $html, $kind);
            self::assertStringContainsString(htmlspecialchars($this->rand(120000), ENT_QUOTES), $html, $kind);
            self::assertStringNotContainsString(htmlspecialchars($this->rand(50000), ENT_QUOTES), $html, $kind . ': no course is shown at its own price.');
        }
        $customer = InProcessPage::run($buyer, CommerceController::class, 'order', ['id' => (string) $orderId]);
        self::assertStringContainsString('Office Skills Bundle', $customer['body']);
        self::assertStringContainsString('Includes: Course A, Course B, Course C', $customer['body']);
        $admin = InProcessPage::run($this->admin(), PaymentAdministrationController::class, 'order', ['id' => (string) $orderId]);
        self::assertStringContainsString('Bundle: Office Skills Bundle', $admin['body']);
        self::assertSame(3, substr_count($admin['body'], 'granted by this bundle'));

        $this->db->executeStatement('SAVEPOINT company_line');
        try {
            $this->db->executeStatement("INSERT INTO commerce_order_items(order_id,product_type,bundle_id,bundle_offer_id,company_id,amount_minor,access_period_seconds,snapshot) VALUES (:o,'bundle',:b,:offer,:c,1000,86400,'{}')",
                ['o' => $orderId, 'b' => $bundle['id'], 'offer' => $bundle['offer'], 'c' => $this->provider]);
            self::fail('A bundle can never be a company line.');
        } catch (DbalException $refused) {
            self::assertStringContainsString('commerce_order_item_beneficiary', $refused->getMessage());
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT company_line');
        }
    }

    public function testOrdersKeepTheCoursesAndPriceTheyWereSoldWith(): void
    {
        $bundle = $this->bundle(['A', 'B', 'C'], 120000, 'Changing Bundle');
        $first = $this->buyer();
        $firstOrder = $this->buy($first, [$bundle['offer']]);
        $before = [$this->records->order($firstOrder), $this->records->items($firstOrder), $this->documentsHtml($firstOrder)];

        $admin = $this->admin();
        $bundles = $this->bundleService();
        $bundles->removeCourse($admin, $bundle['id'], $this->course['C']['id']);
        $bundles->addCourse($admin, $bundle['id'], $this->course['D']['id']);
        $bundles->saveOffer($admin, $bundle['id'], ['price' => '900', 'access_period_value' => '6', 'access_period_unit' => 'months', 'is_active' => '1']);

        self::assertEquals($before, [$this->records->order($firstOrder), $this->records->items($firstOrder), $this->documentsHtml($firstOrder)], 'The earlier order and its documents are unchanged.');
        self::assertSame($this->ids(['A', 'B', 'C']), $this->openCourses($first), 'The earlier buyer keeps A, B and C and gains nothing.');

        $second = $this->buyer();
        $secondOrder = $this->buy($second, [$bundle['offer']]);
        $line = $this->records->items($secondOrder)[0];
        $snapshot = CommerceRepository::decode((string) $line['snapshot']);
        self::assertSame([90000, 15552000, $this->ids(['A', 'B', 'D'])], [(int) $line['amount_minor'], (int) $line['access_period_seconds'], array_column($snapshot['courses'], 'course_id')]);
        self::assertSame($this->ids(['A', 'B', 'D']), $this->openCourses($second), 'A later buyer receives the bundle as it is now.');
        self::assertStringContainsString('Includes: Course A, Course B, Course D', $this->documentsHtml($secondOrder)['invoice']);
    }

    public function testACourseTheLearnerAlreadyHasGainsItsOwnBundleSourceAndKeepsTheOtherOnRefund(): void
    {
        $bundle = $this->bundle(['A', 'B', 'C'], 120000);
        $buyer = $this->buyer();
        $existing = $this->fixture->createEnrolment($buyer->id, $this->course['A']['id'], $this->owner);
        $this->orders->changeBundle($buyer, $bundle['offer']);
        self::assertSame(1, $this->summary($buyer)['bundles'][0]['owned_count'], 'The learner is told they already have one of the courses');
        self::assertSame(120000, $this->summary($buyer)['total_minor'], 'and the bundle price is not reduced.');
        $orderId = $this->buy($buyer, []);
        $line = $this->records->items($orderId)[0];
        $grants = array_column($this->grants((int) $line['id']), null, 'course_id');
        $a = $grants[$this->course['A']['id']];
        self::assertSame([$existing, true], [(int) $a['enrolment_id'], (bool) $a['shared_at_grant']], 'A joins the enrolment the learner already has, as another source.');
        self::assertSame([false, false], [(bool) $grants[$this->course['B']['id']]['shared_at_grant'], (bool) $grants[$this->course['C']['id']]['shared_at_grant']]);
        self::assertSame(['origin', 'bundle'], array_column($this->records->sources($existing), 'source'), 'The ADMIN assignment is recorded as its own source; the bundle adds its own.');
        self::assertSame((int) $line['id'], (int) $this->records->sources($existing)[1]['order_item_id']);
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_enrolments WHERE user_id=:u AND course_id=:c AND status IN ('assigned','active','completed')", ['u' => $buyer->id, 'c' => $this->course['A']['id']]), 'Course A is still one course in the library.');
        self::assertSame([3, 1], [$this->events('bundle_purchased', $buyer->id)[0]['metadata']['courses_granted'], $this->events('bundle_purchased', $buyer->id)[0]['metadata']['courses_already_held']]);

        $this->refunds()->approve($this->admin(), $orderId, (int) $line['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 120000);
        self::assertSame([$this->course['A']['id']], $this->openCourses($buyer), 'Refunding the bundle ends B and C and keeps A, which the ADMIN assignment still grants.');
        self::assertSame(['awaiting_activation', 'revoked'], array_column($this->records->sources($existing), 'state'), 'Only the bundle source was revoked; the ADMIN assignment still waits for the learner to start.');
        self::assertSame('assigned', (string) $this->db->fetchOne('SELECT status FROM course_enrolments WHERE id=:id', ['id' => $existing]));
    }

    public function testOverlappingBundlesShareCoursesWithoutDuplicateAccessAndEachRefundRespectsTheOther(): void
    {
        $x = $this->bundle(['A', 'B'], 100000, 'Bundle X');
        $y = $this->bundle(['B', 'C'], 90000, 'Bundle Y');
        $buyer = $this->buyer();
        $this->orders->changeBundle($buyer, $x['offer']);
        $this->orders->changeBundle($buyer, $y['offer']);
        self::assertSame(['Course B is included in both Bundle X and Bundle Y. Each bundle is a purchase of its own, so you would pay for both.'], $this->summary($buyer)['overlaps'], 'Both may be bought; the overlap is pointed out.');
        $orderId = $this->buy($buyer, []);
        [$lineX, $lineY] = $this->records->items($orderId);
        self::assertSame(array_fill_keys($this->ids(['A', 'B']), false), array_map('boolval', array_column($this->grants((int) $lineX['id']), 'shared_at_grant', 'course_id')));
        self::assertSame([$this->course['B']['id'] => true, $this->course['C']['id'] => false], array_map('boolval', array_column($this->grants((int) $lineY['id']), 'shared_at_grant', 'course_id')));
        self::assertSame($this->ids(['A', 'B', 'C']), $this->openCourses($buyer), 'One open access per course.');
        $courseB = (int) $this->grants((int) $lineX['id'])[1]['enrolment_id'];
        self::assertSame([(int) $lineX['id'], (int) $lineY['id']], array_map('intval', array_column($this->records->sources($courseB), 'order_item_id')), 'Course B has a source from each bundle behind its one enrolment.');

        $refunds = $this->refunds();
        $refunds->approve($this->admin(), $orderId, (int) $lineX['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 100000);
        self::assertSame($this->ids(['B', 'C']), $this->openCourses($buyer), 'Refunding X ends A; B stays, because Y still covers it.');
        $refunds->approve($this->admin(), $orderId, (int) $lineY['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 90000);
        self::assertSame([], $this->openCourses($buyer), 'Refunding Y too ends B and C.');
    }

    public function testARefundIsBoundedByWhatWasPaidAfterAPromotion(): void
    {
        $bundle = $this->bundle(['A', 'B', 'C'], 120000, 'Discounted Bundle');
        $buyer = $this->buyer();
        $this->orders->changeBundle($buyer, $bundle['offer']);
        $code = 'BUNDLES' . strtoupper($this->fixture->suffix());
        PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'course_scope' => 'none', 'bundle_scope' => 'all']);
        self::assertSame(12000, $this->promotions->apply($buyer, $code)->discountMinor);
        $orderId = $this->buy($buyer, []);
        $line = $this->records->items($orderId)[0];
        self::assertSame([108000, 12000], [(int) $this->records->order($orderId)['total_minor'], (int) $line['discount_minor']]);

        $refunds = $this->refunds();
        try {
            $refunds->approve($this->admin(), $orderId, (int) $line['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 108001);
            self::fail('A refund above what was paid for the bundle is refused.');
        } catch (RuntimeException $refused) {
            self::assertSame('The refund exceeds this item’s unrefunded paid amount.', $refused->getMessage());
        }
        $refundId = $refunds->approve($this->admin(), $orderId, (int) $line['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 108000);
        $note = $this->db->fetchAssociative("SELECT * FROM commerce_documents WHERE order_id=:o AND kind='credit_note'", ['o' => $orderId]) ?: [];
        $noteSnapshot = CommerceRepository::decode((string) $note['snapshot']);
        self::assertSame(['bundle', 'Discounted Bundle', 108000], [$noteSnapshot['items'][0]['fulfilment_type'], $noteSnapshot['items'][0]['bundle_title'], $noteSnapshot['total_minor']]);
        self::assertEquals(['price_minor' => 120000, 'discount_minor' => 12000, 'paid_minor' => 108000, 'promotion_code' => $code], $noteSnapshot['refund_line']);
        $noteHtml = (new InvoicePdfRenderer($this->records))->html($note);
        self::assertStringContainsString('Bundle: Discounted Bundle', $noteHtml);
        self::assertStringContainsString('Amount credited: ' . htmlspecialchars($this->rand(108000), ENT_QUOTES), $noteHtml);
        self::assertSame([], $this->openCourses($buyer));
        $refunded = $this->events('bundle_refunded', $buyer->id);
        self::assertCount(1, $refunded);
        self::assertEquals(['bundle_id' => $bundle['id'], 'refund_id' => $refundId, 'amount_minor' => 108000, 'currency' => 'ZAR', 'full_refund' => true, 'courses_revoked' => 3, 'courses_kept' => 0], $refunded[0]['metadata']);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM analytics_events WHERE event_type='course_refunded' AND order_id=:o", ['o' => $orderId]), 'No course is recorded as refunded on its own.');
    }

    public function testTheCartNeverChargesTwiceAndOnlySellsWhatCanBeBought(): void
    {
        $bundle = $this->bundle(['A', 'B', 'C'], 120000, 'Cart Bundle');
        $buyer = $this->buyer();
        $this->orders->changeCart($buyer, $this->course['A']['variant']);
        $this->orders->changeCart($buyer, $this->course['D']['variant']);
        self::assertSame(['Course A'], $this->orders->changeBundle($buyer, $bundle['offer']), 'Course A is taken out: the bundle includes it.');
        $cart = $this->summary($buyer);
        self::assertSame([[$this->course['D']['id']], [$bundle['offer']], 150000], [array_map(static fn(array $i): int => (int) $i['course_id'], $cart['items']), array_map(static fn(array $b): int => (int) $b['id'], $cart['bundles']), $cart['total_minor']], 'A mixed cart: course D at R300 and the bundle at R1,200.');
        self::assertSame(['Includes 3', 'Course A, Course B, Course C'], ['Includes ' . $cart['bundles'][0]['course_count'], implode(', ', $cart['bundles'][0]['course_titles'])]);
        try {
            $this->orders->changeCart($buyer, $this->course['B']['variant']);
            self::fail('A course the bundle in the cart includes cannot be added again.');
        } catch (RuntimeException $refused) {
            self::assertSame('Course B is already included in Cart Bundle in your cart.', $refused->getMessage());
        }

        $now = $this->clock->now();
        $draft = $this->bundle(['A', 'B'], 50000, 'Draft', ['status' => 'draft']);
        $retired = $this->bundle(['A', 'B'], 50000, 'Retired', ['status' => 'retired']);
        $offSale = $this->bundle(['A', 'B'], 50000, 'Off sale', [], false);
        $later = $this->bundle(['A', 'B'], 50000, 'Later', ['available_from' => $now->modify('+1 day')->format(DATE_ATOM)]);
        $over = $this->bundle(['A', 'B'], 50000, 'Over', ['available_until' => $now->format(DATE_ATOM)]);
        $draftCourse = $this->fixture->createCourse($this->owner, $this->provider, 'bundle-draft-course-' . $this->fixture->suffix(), 'Draft course', 'draft');
        $withDraft = BundleFixture::create($this->db, $this->owner, [$this->course['A']['id'], $draftCourse], 50000);
        foreach ([
            'This bundle is not available.' => $draft, 'This bundle is no longer sold.' => $retired, 'This bundle is not available for purchase at the moment.' => $offSale,
            'This bundle is not on sale yet.' => $later, 'This bundle is no longer on sale.' => $over,
            'This bundle includes a course that is no longer available, so it cannot be bought at the moment.' => $withDraft,
        ] as $message => $unavailable) {
            try {
                $this->orders->changeBundle($buyer, $unavailable['offer']);
                self::fail($message);
            } catch (RuntimeException $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }

        // Retired after it reached the cart: placement checks again, so a stale or forged form buys nothing.
        $this->ready($buyer);
        $quote = $this->orders->cart($buyer);
        $this->bundleService()->setStatus($this->admin(), $bundle['id'], 'retired');
        try {
            $this->checkout($buyer)->place($buyer, (int) $quote['id'], (string) $quote['quote'], true);
            self::fail('A retired bundle cannot be bought.');
        } catch (RuntimeException $refused) {
            self::assertSame('Your cart or prices changed. Review your order again.', $refused->getMessage());
        }
        $fresh = $this->orders->cart($buyer);
        try {
            $this->orders->place($buyer, (int) $fresh['id'], (string) $fresh['quote'], BillingFixture::person(), true);
            self::fail('A retired bundle cannot be bought.');
        } catch (RuntimeException $refused) {
            self::assertSame('This bundle is no longer sold.', $refused->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id=:u', ['u' => $buyer->id]));

        $other = $this->buyer();
        $open = $this->bundle(['C', 'D'], 60000, 'Twice');
        $this->orders->changeBundle($other, $open['offer']);
        $cart = $this->orders->cart($other);
        $this->orders->place($other, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person(), true);
        try {
            $this->orders->changeBundle($other, $open['offer']);
            self::fail('An unpaid order already sells this bundle to the learner.');
        } catch (RuntimeException $refused) {
            self::assertSame('An existing order already contains this bundle.', $refused->getMessage());
        }
    }

    public function testPromotionsJudgeABundleAsTheProductBought(): void
    {
        $x = $this->bundle(['A', 'B', 'C'], 120000, 'Promo X');
        $y = $this->bundle(['C', 'D'], 60000, 'Promo Y');
        $buyer = $this->buyer();
        $this->orders->changeBundle($buyer, $x['offer']);
        $suffix = strtoupper($this->fixture->suffix());
        PromotionFixture::create($this->db, $this->owner, ['code' => 'ONLYA' . $suffix], [$this->course['A']['id']]);
        try {
            $this->promotions->apply($buyer, 'ONLYA' . $suffix);
            self::fail('A promotion for course A does not discount a bundle because it contains A.');
        } catch (RuntimeException $refused) {
            self::assertSame('That promo code does not apply to anything in your cart.', $refused->getMessage());
        }
        $this->orders->changeCart($buyer, $this->course['D']['variant']);
        PromotionFixture::create($this->db, $this->owner, ['code' => 'BUNDLES' . $suffix, 'course_scope' => 'none', 'bundle_scope' => 'all']);
        $allBundles = $this->promotions->apply($buyer, 'BUNDLES' . $suffix);
        self::assertSame(['bundle:' . $x['offer'] => 12000], $allBundles->lineDiscounts, 'All bundles: the bundle line only, not course D.');
        PromotionFixture::create($this->db, $this->owner, ['code' => 'COURSES' . $suffix]);
        self::assertSame(['course:' . $this->course['D']['variant'] => 3000], $this->promotions->apply($buyer, 'COURSES' . $suffix)->lineDiscounts, 'All courses: course D only, not the bundle.');
        PromotionFixture::create($this->db, $this->owner, ['code' => 'EVERY' . $suffix, 'bundle_scope' => 'all']);
        self::assertSame(['course:' . $this->course['D']['variant'] => 3000, 'bundle:' . $x['offer'] => 12000], $this->promotions->apply($buyer, 'EVERY' . $suffix)->lineDiscounts, 'Every catalogue product.');
        $this->orders->changeBundle($buyer, $y['offer']);
        PromotionFixture::create($this->db, $this->owner, ['code' => 'ONLYY' . $suffix], [], [$y['id']]);
        $selected = $this->promotions->apply($buyer, 'ONLYY' . $suffix);
        self::assertSame(['bundle:' . $y['offer'] => 6000], $selected->lineDiscounts, 'A selected bundle: that bundle only.');

        $orderId = $this->buy($buyer, []);
        $order = $this->records->order($orderId);
        $before = [$order, $this->records->items($orderId)];
        self::assertSame([[], [$y['id']]], [CommerceRepository::decode((string) $order['snapshot'])['promotion']['eligible_course_ids'], CommerceRepository::decode((string) $order['snapshot'])['promotion']['eligible_bundle_ids']]);
        $this->bundleService()->removeCourse($this->admin(), $x['id'], $this->course['C']['id']);
        $this->db->executeStatement("UPDATE promotions SET bundle_scope='all', discount_value=5000 WHERE code=:c", ['c' => 'ONLYY' . $suffix]);
        self::assertEquals($before, [$this->records->order($orderId), $this->records->items($orderId)], 'The order keeps its promotion and its bundles as they were.');
    }

    /**
     * @param list<string> $keys
     * @param array<string,mixed> $overrides
     * @return array{id:int,offer:int,slug:string,title:string}
     */
    private function bundle(array $keys, int $price, string $title = '', array $overrides = [], bool $onSale = true): array
    {
        return BundleFixture::create($this->db, $this->owner, $this->ids($keys), $price, $overrides + ($title === '' ? [] : ['title' => $title]), 31536000, $onSale);
    }

    /**
     * @param list<string> $keys
     * @return list<int>
     */
    private function ids(array $keys): array
    {
        return array_map(fn(string $key): int => $this->course[$key]['id'], $keys);
    }

    /** @return array{id:int,variant:int,slug:string,title:string} */
    private function paidCourse(string $title, int $price): array
    {
        $slug = 'bundle-course-' . $this->fixture->suffix() . '-' . strtolower(substr($title, -1));
        $id = $this->fixture->createCourse($this->owner, $this->provider, $slug, $title, 'published');
        return ['id' => $id, 'variant' => $this->fixture->createPriceVariant($id, $this->owner, 86400, $price, true), 'slug' => $slug, 'title' => $title];
    }

    private function buyer(): CurrentUser
    {
        $id = $this->fixture->createUser('Bundle buyer', 'bundle-buyer-' . bin2hex(random_bytes(5)) . '@example.test');
        return new CurrentUser($id, Uuid::v4(), 'buyer-' . $id . '@example.invalid', 'Buyer ' . $id, ['STUDENT'], self::PERMISSIONS, Uuid::v4());
    }

    private function admin(): CurrentUser
    {
        $id = $this->fixture->createUser('Bundle administrator', 'bundle-admin-' . bin2hex(random_bytes(5)) . '@example.test');
        return new CurrentUser($id, Uuid::v4(), 'admin-' . $id . '@example.invalid', 'Administrator', ['ADMIN'], (new PermissionCatalog())->keys(), Uuid::v4());
    }

    private function ready(CurrentUser $buyer): void
    {
        $this->billing->saveOwn($buyer, BillingFixture::personFields('Bundle Buyer'));
        $_SESSION['checkout'][$buyer->id] = ['profile_confirmed' => true, 'payment_method' => 'dummy', 'method_token' => 'demo_success', 'invoice_email' => false, 'payment_key' => Uuid::v4()];
    }

    /**
     * Adds the bundle offers to the cart and places and pays the order with the simulated card.
     *
     * @param list<int> $offers
     */
    private function buy(CurrentUser $buyer, array $offers): int
    {
        foreach ($offers as $offer) $this->orders->changeBundle($buyer, $offer);
        $this->ready($buyer);
        $cart = $this->orders->cart($buyer);
        return (int) $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
    }

    /** @return list<int> courses the learner has open access to, by id */
    private function openCourses(CurrentUser $buyer): array
    {
        return array_map('intval', $this->db->fetchFirstColumn("SELECT course_id FROM course_enrolments WHERE user_id=:u AND NOT is_preview AND status IN ('assigned','active','completed') ORDER BY course_id", ['u' => $buyer->id]));
    }

    /** @return list<array<string,mixed>> */
    private function grants(int $itemId): array
    {
        return $this->db->fetchAllAssociative('SELECT course_id, enrolment_id, entitlement_id, shared_at_grant FROM commerce_bundle_grants WHERE order_item_id=:i ORDER BY course_id', ['i' => $itemId]);
    }

    /** @return array<string,mixed> */
    private function summary(CurrentUser $buyer): array
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        return (new CartService($auth, $this->orders, $this->records))->summary();
    }

    private function checkout(CurrentUser $buyer): CheckoutService
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        return new CheckoutService($auth, $this->orders, $this->records, $this->payments, $this->fulfilment, new TransactionManager($this->db), $this->analytics(), $this->billing);
    }

    private function refunds(): RefundAdministrationService
    {
        return new RefundAdministrationService($this->records, new TransactionManager($this->db), IntegrationContainer::get()->get(TransitionService::class), $this->clock, $this->analytics(),new \CattoLearning\Commerce\Application\AccessService($this->records,new TransactionManager($this->db),IntegrationContainer::get()->get(TransitionService::class),$this->clock));
    }

    private function bundleService(): BundleService
    {
        return IntegrationContainer::get()->get(BundleService::class);
    }

    private function analytics(): AnalyticsEventRecorder
    {
        return new AnalyticsEventRecorder(new AnalyticsEventRepository($this->db), $this->clock);
    }

    /** @return array<string,string> */
    private function documentsHtml(int $orderId): array
    {
        $html = [];
        foreach ($this->records->documents($orderId) as $document) $html[(string) $document['kind']] = (new InvoicePdfRenderer($this->records))->html($document);
        return $html;
    }

    private function rand(int $minor): string
    {
        return Money::strictMinorUnits($minor, 'ZAR')->format();
    }

    /** @return list<array<string,mixed>> */
    private function events(string $type, int $userId): array
    {
        return array_map(static function (array $row): array {
            $row['metadata'] = json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        }, $this->db->fetchAllAssociative('SELECT * FROM analytics_events WHERE event_type=:t AND user_id=:u ORDER BY id', ['t' => $type, 'u' => $userId]));
    }
}
