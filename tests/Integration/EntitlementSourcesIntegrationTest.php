<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\{AnalyticsEventRecorder, AnalyticsEventRepository};
use CattoLearning\Auth\{AuthService, CurrentUser, PermissionCatalog};
use CattoLearning\Commerce\Application\{AccessService,BillingProfileService,CheckoutService,CompanyCreditFulfilment,FulfilmentService,OrderService,PaymentService,RefundAdministrationService};
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,Database,TransactionManager};
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture,BundleFixture,DevelopmentFixture,IntegrationContainer,PromotionFixture};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Several entitlement sources behind one visible course: an individual purchase, bundles, free access
 * and an enrolment's own ADMIN or company origin each keep their own period, activation and expiry;
 * the learner sees the course once, and it stays open while any source is valid. Time is moved with a
 * mock clock and the scheduled worker's step is run as maintenance runs it. Every write rolls back.
 */
#[Group('commerce')]
final class EntitlementSourcesIntegrationTest extends TestCase
{
    private const PERMISSIONS = ['COMMERCE.CART.VIEW','COMMERCE.CART.MANAGE','COMMERCE.CHECKOUT.START','COMMERCE.ORDER.VIEW','LEARNING.COURSE.START','ACCOUNT.PROFILE.EDIT'];
    private const DAY = 86400;

    private Database $db;
    private DevelopmentFixture $fixture;
    private MockClock $clock;
    private CommerceRepository $records;
    private OrderService $orders;
    private AccessService $access;
    private FulfilmentService $fulfilment;
    private PaymentService $payments;
    private BillingProfileService $billing;
    private int $owner;
    private int $provider;

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
        $promotions = PromotionFixture::service($this->db, $this->clock);
        $this->orders = new OrderService($this->records, $tx, new CommercePolicy(dirname(__DIR__, 2)), $transitions, $this->clock, $promotions);
        $this->access = new AccessService($this->records, $tx, $transitions, $this->clock);
        $companyCredits = new CompanyCreditFulfilment($this->records, $container->get(AdministrationRepository::class), $container->get(CourseRepository::class), $this->clock);
        $this->fulfilment = new FulfilmentService($this->records, $transitions, $this->access, $tx, $this->orders, $this->clock, $companyCredits, $this->analytics(), $promotions);
        $this->payments = new PaymentService($this->records, $tx, $this->orders, new OmnipayPaymentGatewayAdapter('test', $this->clock), $this->fulfilment, $transitions, $this->clock);
        $this->billing = BillingFixture::service($this->db, $this->clock);
        $this->owner = $this->fixture->createUser('Sources course owner');
        $this->provider = $this->fixture->createCompany($this->owner, 'Sources provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.sources.example.test');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['checkout']);
    }

    public function testABundleLongerThanAnIndividualPurchaseKeepsTheCourseOpenAfterThePurchaseEndsAndIsRefunded(): void
    {
        [$a, $variantA] = $this->course('A', 50000, 30 * self::DAY);
        [$b] = $this->course('B', 40000);
        $buyer = $this->buyer();
        $this->orders->changeCart($buyer, $variantA);
        $individual = $this->place($buyer);
        $enrolment = $this->enrolment($buyer, $a);
        self::assertTrue($this->access->start($enrolment), 'The learner starts course A: its 30 days begin.');
        $individualEnds = $this->clock->now()->modify('+30 days');

        $bundle = BundleFixture::create($this->db, $this->owner, [$a, $b], 60000, [], 365 * self::DAY);
        $this->orders->changeBundle($buyer, $bundle['offer']);
        $bundleOrder = $this->place($buyer);
        $bundleEnds = $this->clock->now()->modify('+365 days');
        self::assertSame(['paid_order', 'bundle'], array_column($this->records->sources($enrolment), 'source'), 'Course A keeps its purchase and gains the bundle as its own source.');
        self::assertSame(['active', 'active'], array_column($this->records->sources($enrolment), 'state'), 'Already started, so the bundle source runs from when it was granted.');
        self::assertSame(1, $this->openCount($buyer, $a), 'The learner sees course A once.');
        $this->assertExpiry($bundleEnds, $enrolment, 'The course stays open until the later source ends.');
        self::assertSame($individualEnds->getTimestamp(), (new \DateTimeImmutable((string) $this->records->sources($enrolment)[0]['access_expires_at']))->getTimestamp(), 'The purchase keeps its own end; nothing was extended.');

        $sources = count($this->records->sources($enrolment));
        $this->fulfilment->fulfil(['state' => 'paid'] + $this->records->order($bundleOrder));
        self::assertCount($sources, $this->records->sources($enrolment), 'Fulfilling the bundle again adds no source.');

        $this->clock->modify('+31 days');
        $this->runMaintenance();
        self::assertSame(['expired', 'active'], array_column($this->records->sources($enrolment), 'state'));
        self::assertTrue($this->access->assertAccess($enrolment), 'The purchase has ended; the bundle keeps the course open.');
        $this->assertExpiry($bundleEnds, $enrolment, 'still until the bundle ends.');

        $this->refund($individual, 50000);
        self::assertTrue($this->access->assertAccess($enrolment), 'Refunding the individual purchase leaves the bundle source.');
        $this->refund($bundleOrder, 60000);
        self::assertSame('cancelled', $this->enrolmentStatus($enrolment), 'With the bundle refunded too, no source keeps the course open.');
    }

    public function testALongerCompanySourceOutlastsAShorterBundleAndSurvivesItsRefund(): void
    {
        [$a] = $this->course('A', 50000);
        [$b] = $this->course('B', 40000);
        $buyer = $this->buyer();
        $enrolment = $this->fixture->createEnrolment($buyer->id, $a, $this->owner, 400 * self::DAY);
        $companyEnds = $this->clock->now()->modify('+400 days');
        $this->db->executeStatement("UPDATE course_enrolments SET source_type='company_credit', status='active', started_at=:start, expires_at=:end WHERE id=:id",
            ['id' => $enrolment, 'start' => $this->clock->now()->format(DATE_ATOM), 'end' => $companyEnds->format(DATE_ATOM)]);
        $bundle = BundleFixture::create($this->db, $this->owner, [$a, $b], 60000, [], 30 * self::DAY);
        $this->orders->changeBundle($buyer, $bundle['offer']);
        $bundleOrder = $this->place($buyer);
        $sources = $this->records->sources($enrolment);
        self::assertSame([['origin', 'active'], ['bundle', 'active']], array_map(static fn(array $s): array => [$s['source'], $s['state']], $sources), 'The company access is recorded as its own source beside the bundle.');
        self::assertSame($companyEnds->getTimestamp(), (new \DateTimeImmutable((string) $sources[0]['access_expires_at']))->getTimestamp(), 'with its own end.');
        $this->assertExpiry($companyEnds, $enrolment, 'The longer company access sets the course end.');

        $this->clock->modify('+31 days');
        $this->runMaintenance();
        self::assertSame(['active', 'expired'], array_column($this->records->sources($enrolment), 'state'));
        self::assertTrue($this->access->assertAccess($enrolment), 'The bundle has ended; the company access keeps the course open.');
        $this->refund($bundleOrder, 60000);
        self::assertSame(['active', 'revoked'], array_column($this->records->sources($enrolment), 'state'), 'A refund revokes the bundle source only.');
        self::assertSame('active', $this->enrolmentStatus($enrolment));
        self::assertSame('cancelled', $this->enrolmentStatus($this->enrolment($buyer, $b, true)), 'Course B had no other source.');
    }

    public function testEachSourceStartsWithTheLearnerAndRunsItsOwnPeriod(): void
    {
        [$a] = $this->course('A', 50000);
        [$b] = $this->course('B', 40000);
        $buyer = $this->buyer();
        $enrolment = $this->fixture->createEnrolment($buyer->id, $a, $this->owner, 60 * self::DAY);
        $bundle = BundleFixture::create($this->db, $this->owner, [$a, $b], 60000, [], 365 * self::DAY);
        $this->orders->changeBundle($buyer, $bundle['offer']);
        $this->place($buyer);
        self::assertSame([['origin', 'awaiting_activation'], ['bundle', 'awaiting_activation']], array_map(static fn(array $s): array => [$s['source'], $s['state']], $this->records->sources($enrolment)), 'Neither has started: the learner has not begun.');
        self::assertTrue($this->access->assertAccess($enrolment));
        self::assertNull($this->db->fetchOne('SELECT expires_at FROM course_enrolments WHERE id=:id', ['id' => $enrolment]));

        $this->clock->modify('+5 days');
        $startedAt = $this->clock->now();
        self::assertTrue($this->access->start($enrolment));
        $ends = array_map(static fn(array $s): int => (new \DateTimeImmutable((string) $s['access_expires_at']))->getTimestamp(), $this->records->sources($enrolment));
        self::assertSame([$startedAt->modify('+60 days')->getTimestamp(), $startedAt->modify('+365 days')->getTimestamp()], $ends, 'Starting the course starts both, each with its own period.');
        $this->assertExpiry($startedAt->modify('+365 days'), $enrolment, 'The course stays open until the later end.');

        $courseB = $this->enrolment($buyer, $b);
        $this->clock->modify('+86 days');
        $this->runMaintenance();
        $sourceB = $this->records->sources($courseB)[0];
        self::assertSame('active', $sourceB['state'], 'Course B, never started, began automatically at its own activation deadline');
        self::assertNull($this->db->fetchOne('SELECT started_at FROM course_enrolments WHERE id=:id', ['id' => $courseB]), 'which does not mark the learner as having started.');
    }

    public function testFreeAccessAndTwoBundlesAreThreeSourcesOfOneCourse(): void
    {
        [$free, $freeVariant] = $this->course('Free', 0);
        [$a] = $this->course('A', 50000);
        [$b] = $this->course('B', 40000);
        $buyer = $this->buyer();
        $enrolment = $this->fulfilment->acceptFree($buyer, $freeVariant);
        $x = BundleFixture::create($this->db, $this->owner, [$free, $a], 60000, ['title' => 'Bundle X']);
        $y = BundleFixture::create($this->db, $this->owner, [$free, $b], 50000, ['title' => 'Bundle Y']);
        $this->orders->changeBundle($buyer, $x['offer']);
        $this->orders->changeBundle($buyer, $y['offer']);
        $order = $this->place($buyer);
        self::assertSame(['free', 'bundle', 'bundle'], array_column($this->records->sources($enrolment), 'source'));
        self::assertSame(1, $this->openCount($buyer, $free), 'Still one course.');
        [$lineX, $lineY] = $this->records->items($order);
        $refunds = $this->refunds();
        $refunds->approve($this->admin(), $order, (int) $lineX['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 60000);
        $refunds->approve($this->admin(), $order, (int) $lineY['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 50000);
        self::assertSame(['active', 'revoked', 'revoked'], array_column($this->records->sources($enrolment), 'state'), 'Both bundles refunded: their sources go; the free access, active since it was accepted, stays.');
        self::assertTrue($this->access->assertAccess($enrolment));
        self::assertSame('cancelled', $this->enrolmentStatus($this->enrolment($buyer, $a, true)));
    }

    /** @return array{0:int,1:int} course and default price variant */
    private function course(string $title, int $price, int $period = 365 * self::DAY): array
    {
        $course = $this->fixture->createCourse($this->owner, $this->provider, 'sources-' . strtolower($title) . '-' . $this->fixture->suffix(), 'Course ' . $title, 'published');
        return [$course, $this->fixture->createPriceVariant($course, $this->owner, $period, $price, true)];
    }

    private function buyer(): CurrentUser
    {
        $id = $this->fixture->createUser('Sources buyer', 'sources-buyer-' . bin2hex(random_bytes(5)) . '@example.test');
        return new CurrentUser($id, Uuid::v4(), 'buyer-' . $id . '@example.invalid', 'Buyer ' . $id, ['STUDENT'], self::PERMISSIONS, Uuid::v4());
    }

    private function admin(): CurrentUser
    {
        $id = $this->fixture->createUser('Sources administrator');
        return new CurrentUser($id, Uuid::v4(), 'admin-' . $id . '@example.invalid', 'Administrator', ['ADMIN'], (new PermissionCatalog())->keys(), Uuid::v4());
    }

    /** Places and pays the cart with the simulated card; returns the order. */
    private function place(CurrentUser $buyer): int
    {
        $this->billing->saveOwn($buyer, BillingFixture::personFields('Sources Buyer'));
        $_SESSION['checkout'][$buyer->id] = ['profile_confirmed' => true, 'payment_method' => 'dummy', 'method_token' => 'demo_success', 'invoice_email' => false, 'payment_key' => Uuid::v4()];
        $cart = $this->orders->cart($buyer);
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        $checkout = new CheckoutService($auth, $this->orders, $this->records, $this->payments, $this->fulfilment, new TransactionManager($this->db), $this->analytics(), $this->billing);
        $order = (int) $checkout->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertSame('fulfilled', $this->records->order($order)['state']);
        return $order;
    }

    private function refund(int $orderId, int $amount): void
    {
        $this->refunds()->approve($this->admin(), $orderId, (int) $this->records->items($orderId)[0]['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, $amount);
    }

    private function refunds(): RefundAdministrationService
    {
        return new RefundAdministrationService($this->records, new TransactionManager($this->db), IntegrationContainer::get()->get(TransitionService::class), $this->clock, $this->analytics(), $this->access);
    }

    /** What the scheduled maintenance does for entitlements, at the mock clock's time. */
    private function runMaintenance(): void
    {
        foreach ($this->records->dueEntitlements($this->clock->now()->format(DATE_ATOM), 200) as $enrolment) $this->access->reconcile($enrolment);
    }

    private function enrolment(CurrentUser $buyer, int $courseId, bool $anyStatus = false): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM course_enrolments WHERE user_id=:u AND course_id=:c' . ($anyStatus ? '' : " AND status IN ('assigned','active','completed')") . ' ORDER BY id DESC LIMIT 1', ['u' => $buyer->id, 'c' => $courseId]);
    }

    private function openCount(CurrentUser $buyer, int $courseId): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM course_enrolments WHERE user_id=:u AND course_id=:c AND status IN ('assigned','active','completed')", ['u' => $buyer->id, 'c' => $courseId]);
    }

    private function enrolmentStatus(int $enrolment): string
    {
        return (string) $this->db->fetchOne('SELECT status FROM course_enrolments WHERE id=:id', ['id' => $enrolment]);
    }

    private function assertExpiry(\DateTimeImmutable $expected, int $enrolment, string $why): void
    {
        self::assertSame($expected->getTimestamp(), (new \DateTimeImmutable((string) $this->db->fetchOne('SELECT expires_at FROM course_enrolments WHERE id=:id', ['id' => $enrolment])))->getTimestamp(), $why);
    }

    private function analytics(): AnalyticsEventRecorder
    {
        return new AnalyticsEventRecorder(new AnalyticsEventRepository($this->db), $this->clock);
    }
}
