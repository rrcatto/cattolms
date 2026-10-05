<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\{AccessService,BillingProfileService,CheckoutService,CompanyCreditFulfilment,CompanyCreditPurchaseService,FulfilmentService,OrderService,PaymentService,RefundAdministrationService};
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Http\CommerceController;
use CattoLearning\Commerce\Http\PaymentAdministrationController;
use CattoLearning\Commerce\Infrastructure\{CommerceRepository,InvoicePdfRenderer};
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,CompanyRepository,Database,TransactionManager};
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,InProcessPage,IntegrationContainer};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The Phase F guarantee: an order and every document made from it keep the billing details they
 * were placed with. Billing profile A is saved and an order placed; the profile then becomes B and
 * the person or company is renamed; the order, its invoice (snapshot, HTML and PDF), a receipt from
 * a later payment, a credit note from a later refund, and the customer and ADMIN order pages all
 * still show A, while a new order shows B. For individual and company purchases alike.
 *
 * Every write rolls back. The pages are rendered by the real controllers on this test's own
 * connection, so they read exactly what the test wrote.
 */
#[Group('commerce')]
final class BillingSnapshotIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private MockClock $clock;
    private CommerceRepository $records;
    private OrderService $orders;
    private PaymentService $payments;
    private FulfilmentService $fulfilment;
    private BillingProfileService $billing;
    private InvoicePdfRenderer $pdfs;
    private int $ownerId;
    private int $ownerCompanyId;

    protected function setUp(): void
    {
        $container = IntegrationContainer::get();
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        // Near real time: the customer order page cancels orders whose payment is overdue by the real clock.
        $this->clock = new MockClock(new \DateTimeImmutable());
        $this->records = new CommerceRepository($this->db);
        $tx = new TransactionManager($this->db);
        $transitions = $container->get(TransitionService::class);
        $this->orders = new OrderService($this->records, $tx, new CommercePolicy(dirname(__DIR__, 2)), $transitions, $this->clock);
        $access = new AccessService($this->records, $tx, $transitions, $this->clock);
        $companyCredits = new CompanyCreditFulfilment($this->records, $container->get(AdministrationRepository::class), $container->get(CourseRepository::class), $this->clock);
        $this->fulfilment = new FulfilmentService($this->records, $transitions, $access, $tx, $this->orders, $this->clock, $companyCredits, $this->analytics());
        $this->payments = new PaymentService($this->records, $tx, $this->orders, new OmnipayPaymentGatewayAdapter('test', $this->clock), $this->fulfilment, $transitions, $this->clock);
        $this->billing = BillingFixture::service($this->db, $this->clock);
        $this->pdfs = new InvoicePdfRenderer($this->records);
        $this->ownerId = $this->fixture->createUser('Snapshot course owner');
        $this->ownerCompanyId = $this->fixture->createCompany($this->ownerId, 'Snapshot provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.provider.example.test');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['checkout'], $_SESSION['company_credit_basket']);
    }

    public function testAnIndividualOrderAndItsDocumentsKeepTheBillingDetailsItWasPlacedWith(): void
    {
        $email = 'snapshot-buyer-' . $this->fixture->suffix() . '@example.test';
        $buyerId = $this->fixture->createUser('Thandiwe Nkosi', $email);
        $buyer = $this->user($buyerId, $email, 'Thandiwe Nkosi', ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START', 'COMMERCE.ORDER.VIEW', 'LEARNING.COURSE.START']);
        $checkout = $this->checkout($buyer);
        $a = BillingFixture::person('Thandiwe Nkosi', '14 Jacaranda Avenue');
        $b = BillingDetails::forPerson(['billing_name' => 'Thandiwe Mokoena', 'address_line_1' => '99 Long Street', 'city' => 'Cape Town', 'postal_code' => '8001', 'country_code' => 'ZA']);

        // Profile A, then an order whose card payment fails.
        $checkout->saveProfile($buyer, $this->personal() + BillingFixture::personFields('Thandiwe Nkosi', '14 Jacaranda Avenue'));
        $checkout->selectMethod($buyer, 'dummy', 'demo_failure', false);
        $variant = $this->paidCourse('Snapshot course one');
        $this->orders->changeCart($buyer, $variant);
        $cart = $this->orders->cart($buyer);
        $orderId = $checkout->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertNotNull($orderId);
        self::assertSame('failed', $this->records->payments($orderId)[0]['state']);
        self::assertSame($orderId, $checkout->place($buyer, (int) $cart['id'], (string) $cart['quote'], true), 'A replayed placement is the same order.');
        $this->assertBilling($a, $this->orderBilling($orderId));
        $invoice = $this->onlyDocument($orderId, 'invoice');
        $this->assertBilling($a, $this->documentBilling($invoice));
        $pdfBefore = $this->pdfs->render($invoice);

        // The profile becomes B and the person is renamed.
        $this->billing->saveOwn($buyer, $b->toRow());
        $this->db->executeStatement("UPDATE users SET first_name='Renamed', last_name='Person', display_name='Renamed Person' WHERE id=:id", ['id' => $buyerId]);
        self::assertTrue($b->equals($this->billing->forUser($buyerId) ?? $a));

        // A retried payment succeeds after the change: still one invoice, receipt from the same snapshot.
        $checkout->retry($buyer, $orderId, Uuid::v4(), 'dummy', 'demo_success');
        self::assertSame('fulfilled', $this->records->order($orderId)['state']);
        $this->assertBilling($a, $this->orderBilling($orderId));
        self::assertSame($invoice, $this->onlyDocument($orderId, 'invoice'), 'The invoice is untouched by the change and the retry.');
        $this->assertBilling($a, $this->documentBilling($this->onlyDocument($orderId, 'receipt')));
        $this->assertShowsAButNotB($this->pdfs->html($invoice), $a, $b, 'invoice HTML');
        self::assertSame($pdfBefore, $this->pdfs->render($invoice), 'The invoice PDF is the one generated before the change.');
        $this->assertShowsAButNotB($this->pdfs->html($this->onlyDocument($orderId, 'receipt')), $a, $b, 'receipt HTML');

        // A refund after the change: its credit note carries the order's billing, not the profile's.
        $item = $this->records->items($orderId)[0];
        $this->refunds()->approve($this->finance($buyerId), $orderId, (int) $item['id'], Uuid::v4(), 'goodwill', 'Goodwill after a billing change.', 1, 1000);
        $creditNote = $this->onlyDocument($orderId, 'credit_note');
        $this->assertBilling($a, $this->documentBilling($creditNote));
        $this->assertShowsAButNotB($this->pdfs->html($creditNote), $a, $b, 'credit note HTML');

        // The customer's and ADMIN's order pages read the order, not the profile.
        $this->assertShowsAButNotB($this->page($buyer, CommerceController::class, 'order', ['id' => $orderId]), $a, $b, 'customer order page');
        $this->assertShowsAButNotB($this->page($this->finance($buyerId), PaymentAdministrationController::class, 'order', ['id' => $orderId]), $a, $b, 'ADMIN order page');
        // Deleting the mutable profile cannot leave an issued document incomplete.
        $this->db->executeStatement('DELETE FROM user_billing_profiles WHERE user_id=:id', ['id' => $buyerId]);
        $this->assertShowsAButNotB($this->pdfs->html($invoice), $a, $b, 'invoice HTML with no profile left');
        $this->assertShowsAButNotB($this->page($this->finance($buyerId), PaymentAdministrationController::class, 'order', ['id' => $orderId]), $a, $b, 'ADMIN order page with no profile left');
        $this->billing->saveOwn($buyer, $b->toRow());

        // A new order is placed with B.
        $checkout->selectMethod($buyer, 'eft', '', false);
        $this->orders->changeCart($buyer, $this->paidCourse('Snapshot course two'));
        $cart = $this->orders->cart($buyer);
        $second = $checkout->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertNotNull($second);
        $this->assertBilling($b, $this->orderBilling($second));
        $this->assertBilling($b, $this->documentBilling($this->onlyDocument($second, 'invoice')));
        $this->assertBilling($a, $this->orderBilling($orderId), 'The first order is still A.');
    }

    public function testACompanyCreditOrderAndItsDocumentsKeepTheCompanyBillingItWasPlacedWith(): void
    {
        $suffix = $this->fixture->suffix();
        $email = 'snapshot-company-admin-' . $suffix . '@example.test';
        $adminId = $this->fixture->createUser('Company administrator', $email);
        $companyId = $this->fixture->createCompany($adminId, 'Acme Training ' . $suffix, 'acme-' . $suffix . '.example.test');
        $this->db->executeStatement("INSERT INTO company_users(company_id,user_id,company_role,status) VALUES (:company,:user,'owner','active')", ['company' => $companyId, 'user' => $adminId]);
        $admin = $this->user($adminId, $email, 'Company administrator', ['COMPANY.CREDIT.MANAGE', 'COMPANY.BILLING.MANAGE', 'COMMERCE.CHECKOUT.START', 'COMMERCE.ORDER.VIEW']);
        // The administrator's own billing profile is not the company's.
        $this->billing->saveOwn($admin, BillingFixture::personFields('Administrator Personally', '5 Private Road'));
        $purchases = $this->companyPurchases();
        $a = BillingFixture::company('Acme Training (Pty) Ltd', '1 Dock Road');
        $b = BillingDetails::forCompany(['billing_name' => 'Acme Learning Holdings', 'tax_registration_number' => '4111111111', 'address_line_1' => '200 Main Road', 'city' => 'Johannesburg', 'country_code' => 'ZA']);

        $purchases->add($admin, $companyId, $this->paidCourse('Company snapshot course'), 3);
        $review = $purchases->review($admin, $companyId);
        $orderId = $purchases->place($admin, $companyId, $review['purchase_key'], $review['quote'], $a, 'dummy', 'demo_failure', false, true);
        self::assertSame($orderId, $purchases->place($admin, $companyId, $review['purchase_key'], $review['quote'], $b, 'dummy', 'demo_failure', false, true), 'A replayed placement keeps its order and touches nothing.');
        self::assertTrue($a->equals($this->billing->forCompany($companyId) ?? $b), 'Checkout saved the company billing profile, and the replay did not change it.');
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE event_key='company.billing_updated' AND metadata->>'company_id'=:company", ['company' => (string) $companyId]));
        $this->assertBilling($a, $this->orderBilling($orderId));
        $snapshot = CommerceRepository::decode((string) $this->records->order($orderId)['snapshot']);
        self::assertSame([$adminId, $email], [$snapshot['purchaser_user_id'], $snapshot['purchaser_email']], 'The administrator is recorded as the purchaser; the company is billed.');
        self::assertNotSame('Administrator Personally', $snapshot['billing']['name']);
        $invoice = $this->onlyDocument($orderId, 'invoice');
        $this->assertBilling($a, $this->documentBilling($invoice));
        $pdfBefore = $this->pdfs->render($invoice);

        // The company profile becomes B and the company is renamed.
        self::assertTrue($this->billing->saveForCompany($admin, $companyId, $b));
        $this->db->executeStatement("UPDATE companies SET name='Renamed Company' WHERE id=:id", ['id' => $companyId]);

        $this->checkout($admin)->retry($admin, $orderId, Uuid::v4(), 'dummy', 'demo_success');
        self::assertSame('fulfilled', $this->records->order($orderId)['state']);
        self::assertSame($invoice, $this->onlyDocument($orderId, 'invoice'));
        $this->assertBilling($a, $this->documentBilling($this->onlyDocument($orderId, 'receipt')));
        $this->assertShowsAButNotB($this->pdfs->html($invoice), $a, $b, 'company invoice HTML');
        self::assertSame($pdfBefore, $this->pdfs->render($invoice));

        $item = $this->records->items($orderId)[0];
        $this->refunds()->approve($this->finance($adminId), $orderId, (int) $item['id'], Uuid::v4(), 'voluntary', 'Unused credit after a billing change.', 1, 0);
        $creditNote = $this->onlyDocument($orderId, 'credit_note');
        $this->assertBilling($a, $this->documentBilling($creditNote));
        $this->assertShowsAButNotB($this->pdfs->html($creditNote), $a, $b, 'company credit note HTML');
        $this->assertShowsAButNotB($this->page($admin, CommerceController::class, 'order', ['id' => $orderId]), $a, $b, 'company order page');
        $this->assertShowsAButNotB($this->page($this->finance($adminId), PaymentAdministrationController::class, 'order', ['id' => $orderId]), $a, $b, 'ADMIN company order page');
        $this->db->executeStatement('DELETE FROM company_billing_profiles WHERE company_id=:id', ['id' => $companyId]);
        $this->assertShowsAButNotB($this->pdfs->html($creditNote), $a, $b, 'company credit note HTML with no profile left');
        $this->billing->saveForCompany($admin, $companyId, $b);

        $purchases->add($admin, $companyId, $this->paidCourse('Second company course'), 1);
        $review = $purchases->review($admin, $companyId);
        $second = $purchases->place($admin, $companyId, $review['purchase_key'], $review['quote'], $b, 'eft', '', false, true);
        $this->assertBilling($b, $this->orderBilling($second));
        $this->assertBilling($a, $this->orderBilling($orderId));
    }

    /**
     * The snapshot holds exactly these details. JSONB keeps its own key order, so the comparison
     * ignores order, and the snapshot must also rebuild into identical details.
     *
     * @param array<string,mixed> $snapshot
     */
    private function assertBilling(BillingDetails $expected, array $snapshot, string $message = ''): void
    {
        self::assertEquals($expected->toSnapshot(), $snapshot, $message);
        self::assertTrue(BillingDetails::fromSnapshot($snapshot)->equals($expected), $message);
    }

    private function assertShowsAButNotB(string $html, BillingDetails $a, BillingDetails $b, string $where): void
    {
        foreach ($a->documentLines() as $line) {
            self::assertStringContainsString(htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html, $where . ' shows ' . $line);
        }
        self::assertStringNotContainsString(htmlspecialchars($b->line1, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html, $where . ' does not show the new address.');
        self::assertStringNotContainsString(htmlspecialchars($b->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html, $where . ' does not show the new billing name.');
    }

    /** @return array<string,mixed> */
    private function orderBilling(int $orderId): array
    {
        return CommerceRepository::decode((string) $this->records->order($orderId)['snapshot'])['billing'];
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function documentBilling(array $document): array
    {
        return CommerceRepository::decode((string) $document['snapshot'])['billing'];
    }

    /** @return array<string,mixed> */
    private function onlyDocument(int $orderId, string $kind): array
    {
        $documents = array_values(array_filter($this->records->documents($orderId), static fn(array $d): bool => $d['kind'] === $kind));
        self::assertCount(1, $documents, 'Exactly one ' . $kind . '.');
        return $documents[0];
    }

    /**
     * @param class-string $controller
     * @param array<string,mixed> $attributes
     */
    private function page(CurrentUser $user, string $controller, string $method, array $attributes): string
    {
        $page = InProcessPage::run($user, $controller, $method, $attributes);
        self::assertSame(200, $page['status']);
        return $page['body'];
    }

    /** @param list<string> $permissions */
    private function user(int $id, string $email, string $name, array $permissions): CurrentUser
    {
        return new CurrentUser($id, Uuid::v4(), $email, $name, [], $permissions, Uuid::v4());
    }

    private function finance(int $id): CurrentUser
    {
        return $this->user($id, 'finance@example.invalid', 'Finance ADMIN', ['PLATFORM.ORDER.VIEW', 'PLATFORM.PAYMENT.VIEW', 'PLATFORM.PAYMENT.MANAGE', 'PLATFORM.PAYMENT.RECONCILE', 'PLATFORM.REFUND.VIEW', 'PLATFORM.REFUND.MANAGE']);
    }

    /** @return array<string,string> */
    private function personal(): array
    {
        return ['first_name' => 'Thandiwe', 'last_name' => 'Nkosi', 'mobile_number' => '0821234567', 'identification_number' => '9001015009087'];
    }

    private function paidCourse(string $title): int
    {
        $course = $this->fixture->createCourse($this->ownerId, $this->ownerCompanyId, 'snapshot-' . $this->fixture->suffix(), $title, 'published');
        return (int) $this->db->fetchOne(
            "INSERT INTO course_price_variants(public_id,course_id,access_period_seconds,price_minor_units,currency_code,position,created_by_user_id,updated_by_user_id) VALUES (:public,:course,86400,12345,'ZAR',1,:user,:user) RETURNING id",
            ['public' => Uuid::v4(), 'course' => $course, 'user' => $this->ownerId]
        );
    }

    private function checkout(CurrentUser $actor): CheckoutService
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $actor);
        return new CheckoutService($auth, $this->orders, $this->records, $this->payments, $this->fulfilment, new TransactionManager($this->db), $this->analytics(), $this->billing);
    }

    private function companyPurchases(): CompanyCreditPurchaseService
    {
        $container = IntegrationContainer::get();
        return new CompanyCreditPurchaseService($this->records, $container->get(CompanyRepository::class), $container->get(AdministrationRepository::class),
            new TransactionManager($this->db), new CommercePolicy(dirname(__DIR__, 2)), $container->get(TransitionService::class), $this->payments, $this->clock, $this->analytics(), $this->billing);
    }

    private function refunds(): RefundAdministrationService
    {
        return new RefundAdministrationService($this->records, new TransactionManager($this->db), IntegrationContainer::get()->get(TransitionService::class), $this->clock, $this->analytics());
    }

    private function analytics(): AnalyticsEventRecorder
    {
        return new AnalyticsEventRecorder(new AnalyticsEventRepository($this->db), $this->clock);
    }
}
