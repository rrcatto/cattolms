<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Infrastructure\InvoicePdfRenderer;
use CattoLearning\Tests\Support\FakeMailer;
use CattoLearning\Commerce\Application\{AccessService,CompanyCreditFulfilment,FulfilmentService,OrderService,PaymentService,PaymentAdministrationService,RefundAdministrationService,CartService,CheckoutService,CommerceMaintenance};
use CattoLearning\Commerce\Contract\PaymentGatewayInterface;
use CattoLearning\Commerce\Domain\{PaymentRequest,PaymentResult};
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,Database,TransactionManager};
use CattoLearning\Support\{Money,Uuid};
use CattoLearning\Tests\Support\{DevelopmentFixture,IntegrationContainer};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** All fixture writes roll back; immutable financial history has no test-only deletion loophole. */
#[Group('commerce')]
final class CommercePurchaseIntegrationTest extends TestCase
{
    private Database $db;
    private CommerceRepository $records;
    private OrderService $orders;
    private AccessService $access;
    private FulfilmentService $fulfilment;
    private PaymentService $payments;
    private MockClock $clock;
    private CurrentUser $actor;
    private int $variant;
    private int $course;

    protected function setUp(): void
    {
        $container=IntegrationContainer::get();
        $this->db=IntegrationContainer::db();
        $this->db->beginTransaction();
        $fixture=new DevelopmentFixture($this->db);
        $user=$fixture->createUser('Commerce fixture', 'commerce-'.bin2hex(random_bytes(6)).'@example.invalid');
        $company=$fixture->createCompany($user,'Commerce fixture','commerce-'.bin2hex(random_bytes(5)).'.invalid');
        $this->course=$fixture->createCourse($user,$company,'commerce-'.bin2hex(random_bytes(5)),'Frozen course title','published');
        $this->variant=(int)$this->db->fetchOne('INSERT INTO course_price_variants(public_id,course_id,access_period_seconds,price_minor_units,currency_code,position,created_by_user_id,updated_by_user_id) VALUES (:public,:course,86400,12345,:currency,1,:user,:user) RETURNING id',['currency'=>'ZAR','public'=>Uuid::v4(),'course'=>$this->course,'user'=>$user]);
        $this->actor=new CurrentUser($user,Uuid::v4(),'buyer@example.invalid','Original buyer',[],['COMMERCE.CART.VIEW','COMMERCE.CART.MANAGE','COMMERCE.CHECKOUT.START','COMMERCE.ORDER.VIEW','LEARNING.COURSE.START'],Uuid::v4());
        $this->clock=new MockClock('2026-09-12T12:00:00+02:00');
        $this->records=new CommerceRepository($this->db);
        $tx=new TransactionManager($this->db);
        $transitions=$container->get(TransitionService::class);
        $this->orders=new OrderService($this->records,$tx,new CommercePolicy(dirname(__DIR__,2)),$transitions,$this->clock);
        $this->access=new AccessService($this->records,$tx,$transitions,$this->clock);
        $companyCredits=new CompanyCreditFulfilment($this->records,$container->get(AdministrationRepository::class),$container->get(CourseRepository::class),$this->clock);
        $this->fulfilment=new FulfilmentService($this->records,$transitions,$this->access,$tx,$this->orders,$this->clock,$companyCredits);
        $this->payments=$this->paymentService(new OmnipayPaymentGatewayAdapter('test',$this->clock));
    }
    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['guest_cart'], $_SESSION['checkout']);
    }
    private function paymentService(PaymentGatewayInterface $gateway): PaymentService
    {
        return new PaymentService($this->records,new TransactionManager($this->db),$this->orders,$gateway,$this->fulfilment,IntegrationContainer::get()->get(TransitionService::class),$this->clock);
    }
    private function place(): int
    {
        $this->orders->changeCart($this->actor,$this->variant);
        $cart=$this->orders->cart($this->actor);
        return $this->orders->place($this->actor,(int)$cart['id'],(string)$cart['quote'],'Buyer Billing','Billing address',true);
    }
    public function testInvoiceSurvivesFailureRetryAndDuplicateConfirmationFulfilsOnce(): void
    {
        $order=$this->place();
        self::assertCount(1,$this->records->documents($order));
        self::assertSame('awaiting_payment',$this->records->order($order)['state']);
        $this->payments->purchase($this->actor,$order,Uuid::v4(),'demo_failure');
        self::assertSame('awaiting_payment',$this->records->order($order)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE ce.user_id=:user', ['user'=>$this->actor->id]));
        $key=Uuid::v4();
        $payment=$this->payments->purchase($this->actor,$order,$key,'demo_success');
        self::assertSame($payment,$this->payments->purchase($this->actor,$order,$key,'demo_success'));
        $p=$this->records->payment($payment);
        $this->payments->confirm('dummy',new PaymentResult($payment,'paid',Money::strictMinorUnits(12345,'ZAR'),(string)$p['provider_reference']));
        self::assertSame('fulfilled',$this->records->order($order)['state']);
        self::assertCount(2,$this->records->documents($order));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE ce.user_id=:user', ['user'=>$this->actor->id]));
        self::assertSame(1,(int)$this->db->fetchOne("SELECT COUNT(*) FROM commerce_outbox WHERE payload->>'user_id'=:user", ['user'=>(string)$this->actor->id]));
    }
    public function testPlacedSnapshotSurvivesLiveEditsAndRepeatedCheckout(): void
    {
        $this->orders->changeCart($this->actor,$this->variant); $cart=$this->orders->cart($this->actor);
        $order=$this->orders->place($this->actor,(int)$cart['id'],(string)$cart['quote'],'Billing name','Address',true);
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=999 WHERE id=:id',['id'=>$this->variant]);
        $this->db->executeStatement("UPDATE courses SET title='Changed course' WHERE id=:id",['id'=>$this->course]);
        self::assertSame($order,$this->orders->place($this->actor,(int)$cart['id'],(string)$cart['quote'],'Different billing','',true));
        $snapshot=$this->orders->view($this->actor,$order)['details'];
        self::assertSame('Frozen course title',$snapshot['items'][0]['course_title']);
        self::assertSame(12345,$snapshot['total_minor']);
        self::assertSame('Billing name',$snapshot['billing_name']);
    }
    public function testChangedPriceRequiresAnotherCheckoutReview(): void
    {
        $this->orders->changeCart($this->actor,$this->variant);$cart=$this->orders->cart($this->actor);
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=20000 WHERE id=:id',['id'=>$this->variant]);
        $this->expectException(\RuntimeException::class);
        $this->orders->place($this->actor,(int)$cart['id'],(string)$cart['quote'],'Buyer','',true);
    }
    public function testUnpaidDeadlineCancelsWithoutDeletingInvoice(): void
    {
        $id=$this->place();$this->clock->modify('+7 days');
        self::assertTrue($this->orders->cancelDue($id));
        self::assertFalse($this->orders->cancelDue($id));
        self::assertSame('cancelled',$this->records->order($id)['state']);
        self::assertCount(1,$this->records->documents($id));
    }
    public function testAutomaticActivationDoesNotMarkLearningStartedAndExpiryIsExact(): void
    {
        $id=$this->place();$this->payments->purchase($this->actor,$id,Uuid::v4(),'demo_success');
        $enrolment=(int)$this->db->fetchOne('SELECT e.enrolment_id FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE ce.user_id=:user', ['user'=>$this->actor->id]);
        $this->clock->modify('+90 days -1 second');
        self::assertSame('awaiting_activation',$this->access->reconcile($enrolment)['state']);
        $this->clock->modify('+1 second');
        self::assertSame('active',$this->access->reconcile($enrolment)['state']);
        self::assertNull($this->db->fetchOne('SELECT started_at FROM course_enrolments WHERE id=:id',['id'=>$enrolment]));
        $this->clock->modify('+1 day');
        self::assertSame('expired',$this->access->reconcile($enrolment)['state']);
        self::assertSame('assigned',$this->db->fetchOne('SELECT status FROM course_enrolments WHERE id=:id',['id'=>$enrolment]));
        $this->expectException(\InvalidArgumentException::class);$this->access->assertAccess($enrolment);
    }
    public function testVoluntaryStartDoesNotRestartAnAutomaticallyRunningClock(): void
    {
        $id=$this->place();$this->payments->purchase($this->actor,$id,Uuid::v4(),'demo_success');
        $enrolment=(int)$this->db->fetchOne('SELECT e.enrolment_id FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE ce.user_id=:user', ['user'=>$this->actor->id]);
        $this->clock->modify('+90 days +1 hour');
        $this->access->start($enrolment);$e=$this->records->entitlement($enrolment);
        self::assertNotSame($e['access_started_at'],$e['learner_started_at']);
        $this->clock->modify('+1 hour');$this->access->start($enrolment);
        self::assertSame($e['access_expires_at'],$this->records->entitlement($enrolment)['access_expires_at']);
    }
    public function testFreeAcceptanceStartsAccessWithoutFinancialDocuments(): void
    {
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=0 WHERE id=:id',['id'=>$this->variant]);
        $enrolment=$this->fulfilment->acceptFree($this->actor,$this->variant);
        self::assertSame('active',$this->records->entitlement($enrolment)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id=:user', ['user'=>$this->actor->id]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_documents d JOIN commerce_orders o ON o.id=d.order_id WHERE o.purchaser_user_id=:user', ['user'=>$this->actor->id]));
    }
    public function testDatabaseRejectsDocumentMutation(): void
    {
        $id=$this->place();
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->db->executeStatement("UPDATE commerce_documents SET snapshot='{}' WHERE order_id=:id",['id'=>$id]);
    }
    public function testAnotherPurchaserCannotReadAnOrder(): void
    {
        $id=$this->place();
        $other=new CurrentUser($this->actor->id+999999,Uuid::v4(),'other@example.invalid','Other',[],['COMMERCE.ORDER.VIEW'],Uuid::v4());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->orders->view($other,$id);
    }
    public function testPendingConfirmationAndAmountMismatchDoNotFulfil(): void
    {
        $gateway=new class implements PaymentGatewayInterface {
            public function id(): string {return 'test-double';}
            public function capabilities(): array {return ['purchase'];}
            public function purchase(PaymentRequest $request): PaymentResult {return new PaymentResult($request->transactionId,'pending',$request->amount);}
        };
        $service=$this->paymentService($gateway);$id=$this->place();
        $payment=$service->purchase($this->actor,$id,Uuid::v4(),'test');
        self::assertSame('awaiting_payment',$this->records->order($id)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN course_enrolments ce ON ce.id=e.enrolment_id WHERE ce.user_id=:user', ['user'=>$this->actor->id]));
        $this->expectException(\RuntimeException::class);
        $service->confirm('test-double',new PaymentResult($payment,'paid',Money::strictMinorUnits(1,'ZAR'),'test-reference'));
    }

    private function auth(?CurrentUser $actor): AuthService
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $actor);
        return $auth;
    }

    private function checkoutService(): CheckoutService
    {
        return new CheckoutService($this->auth($this->actor), $this->orders, $this->records, $this->payments, $this->fulfilment, new TransactionManager($this->db));
    }

    /** @return array<string,string> */
    private function profileFields(): array
    {
        return ['first_name'=>'Invoice', 'last_name'=>'Buyer', 'mobile_number'=>'0821234567', 'billing_address'=>'10 Test Street, Pretoria, 0001', 'identification_number'=>'9001015009087'];
    }

    public function testGuestCartMergesOnceAfterSignIn(): void
    {
        $_SESSION['guest_cart'] = [];
        $cart = new CartService($this->auth(null), $this->orders, $this->records);
        $cart->change($this->variant);
        $cart->change($this->variant);
        self::assertSame(1, $cart->summary()['count']);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_carts WHERE user_id=:user', ['user'=>$this->actor->id]));
        $signedIn = new CartService($this->auth($this->actor), $this->orders, $this->records);
        self::assertSame(1, $signedIn->summary()['count']);
        self::assertSame(1, $signedIn->summary()['count']);
        self::assertArrayNotHasKey('guest_cart', $_SESSION);
        $signedIn->change($this->variant, true);
        self::assertSame(0, $signedIn->summary()['count']);
    }

    public function testCheckoutCollectsDetailsBeforeOrderAndEftDoesNotCharge(): void
    {
        $checkout = $this->checkoutService();
        $checkout->saveProfile($this->actor, $this->profileFields());
        $checkout->selectMethod($this->actor, 'eft', 'demo_success', true);
        self::assertSame(0, $this->records->orderCount($this->actor->id));
        $this->orders->changeCart($this->actor, $this->variant);
        $cart = $this->orders->cart($this->actor);
        $id = $checkout->place($this->actor, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertNotNull($id);
        self::assertSame($id, $checkout->place($this->actor, (int) $cart['id'], (string) $cart['quote'], true));
        self::assertSame('awaiting_payment', $this->records->order($id)['state']);
        self::assertSame('eft', $this->records->paymentMethod($id));
        self::assertSame([], $this->records->payments($id));
        self::assertSame('10 Test Street, Pretoria, 0001', $this->auth($this->actor)->profile($this->actor->id)['billing_address']);
        $invoice = $this->records->documents($id)[0];
        $details = CommerceRepository::decode((string) $invoice['snapshot']);
        self::assertTrue($details['invoice_email']);
        self::assertArrayNotHasKey('identification_number', $details, 'Identity numbers must not be exposed on invoices.');
    }

    public function testMissingProfileCannotSkipToPlacement(): void
    {
        $this->orders->changeCart($this->actor, $this->variant);
        $cart = $this->orders->cart($this->actor);
        $this->expectException(\RuntimeException::class);
        $this->checkoutService()->place($this->actor, (int) $cart['id'], (string) $cart['quote'], true);
    }

    public function testInvalidIdCannotAdvanceCheckout(): void
    {
        $fields = $this->profileFields(); $fields['identification_number'] = '123';
        $this->expectException(\RuntimeException::class);
        $this->checkoutService()->saveProfile($this->actor, $fields);
    }

    public function testDeclineCanSwitchToEftThenRetryWithoutReplacingInvoice(): void
    {
        $checkout = $this->checkoutService();
        $checkout->saveProfile($this->actor, $this->profileFields());
        $checkout->selectMethod($this->actor, 'dummy', 'demo_failure', false);
        $this->orders->changeCart($this->actor, $this->variant);
        $cart = $this->orders->cart($this->actor);
        $id = $checkout->place($this->actor, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertNotNull($id);
        self::assertSame('failed', $this->records->payments($id)[0]['state']);
        $invoice = $this->records->documents($id)[0];
        $checkout->retry($this->actor, $id, Uuid::v4(), 'eft', 'demo_success');
        self::assertSame('eft', $this->records->paymentMethod($id));
        self::assertCount(1, $this->records->payments($id));
        $checkout->retry($this->actor, $id, Uuid::v4(), 'dummy', 'demo_success');
        self::assertSame('fulfilled', $this->records->order($id)['state']);
        self::assertSame($invoice, $this->records->documents($id)[0]);
    }

    public function testPdfIsStableAndInvoiceEmailIsDeliveredOnce(): void
    {
        $this->orders->changeCart($this->actor, $this->variant);
        $cart = $this->orders->cart($this->actor);
        $id = $this->orders->place($this->actor, (int) $cart['id'], (string) $cart['quote'], 'Billing Buyer', 'Address', true, ['invoice_email'=>true]);
        $invoice = $this->records->documents($id)[0];
        $pdfs = new InvoicePdfRenderer($this->records);
        $bytes = $pdfs->render($invoice);
        self::assertStringStartsWith('%PDF-', $bytes);
        self::assertSame($bytes, $pdfs->render($invoice));
        $mailer = new FakeMailer();
        $maintenance = new CommerceMaintenance($this->records, $this->orders, $this->access, $pdfs, $mailer, new TransactionManager($this->db), $this->clock);
        $maintenance->deliverInvoices(); $maintenance->deliverInvoices();
        $messages = array_values(array_filter($mailer->messages, fn (array $m): bool => ($m['number'] ?? '') === $invoice['number']));
        self::assertCount(1, $messages);
        self::assertSame($bytes, $messages[0]['pdf']);
    }

    public function testFreeCartCheckoutHasNoInvoiceOrPayment(): void
    {
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=0 WHERE id=:id', ['id'=>$this->variant]);
        $this->orders->changeCart($this->actor, $this->variant);
        $cart = $this->orders->cart($this->actor);
        $checkout = $this->checkoutService();
        $checkout->saveProfile($this->actor, $this->profileFields());
        $checkout->selectMethod($this->actor, 'eft', 'demo_success', true);
        self::assertNull($checkout->place($this->actor, (int) $cart['id'], (string) $cart['quote'], true));
        self::assertSame(0, $this->records->orderCount($this->actor->id));
        self::assertSame([], $this->orders->cart($this->actor)['items']);
    }

    private function financeAdmin(): CurrentUser
    {
        return new CurrentUser($this->actor->id,Uuid::v4(),'finance@example.invalid','Finance ADMIN',[],
            ['PLATFORM.ORDER.VIEW','PLATFORM.PAYMENT.MANAGE','PLATFORM.PAYMENT.RECONCILE','PLATFORM.REFUND.MANAGE'],Uuid::v4());
    }

    public function testBankEvidenceConfirmsFullPaymentAndIdempotentApproval(): void
    {
        $id=$this->place(); $this->records->selectPaymentMethod($id,'eft');
        $service=new PaymentAdministrationService($this->records,new TransactionManager($this->db),$this->payments,$this->fulfilment,IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $admin=$this->financeAdmin(); $key=Uuid::v4();
        $payment=$service->confirmBank($admin,$id,$key,12345,'2026-09-12T11:00:00+02:00','BANK-QA-'.Uuid::v4(),'Matched bank statement and order.');
        self::assertSame($payment,$service->confirmBank($admin,$id,$key,12345,'2026-09-12T11:00:00+02:00','BANK-QA-'.Uuid::v4(),'Matched bank statement and order.'));
        self::assertSame('fulfilled',$this->records->order($id)['state']);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_manual_payment_evidence WHERE order_id=:id',['id'=>$id]));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:id',['id'=>$id]));
        self::assertCount(2,$this->records->documents($id));
    }

    public function testBankAmountMismatchCannotCreatePaymentOrAccess(): void
    {
        $id=$this->place(); $this->records->selectPaymentMethod($id,'eft');
        $service=new PaymentAdministrationService($this->records,new TransactionManager($this->db),$this->payments,$this->fulfilment,IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        try {
            $service->confirmBank($this->financeAdmin(),$id,Uuid::v4(),100,'2026-09-12T11:00:00+02:00','BANK-MISMATCH','Amount did not match invoice.');
            self::fail('A partial bank receipt cannot fulfil the order.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('exact order total',$error->getMessage());
        }
        self::assertSame([],$this->records->payments($id));
        self::assertSame('awaiting_payment',$this->records->order($id)['state']);
    }

    public function testLateBankPaymentNeedsExplicitReconciliationBeforeFulfilment(): void
    {
        $id=$this->place(); $this->records->selectPaymentMethod($id,'eft'); $this->clock->modify('+8 days');
        $service=new PaymentAdministrationService($this->records,new TransactionManager($this->db),$this->payments,$this->fulfilment,IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $admin=$this->financeAdmin();
        $service->confirmBank($admin,$id,Uuid::v4(),12345,'2026-09-20T11:00:00+02:00','BANK-LATE-'.Uuid::v4(),'Late receipt verified against bank.');
        self::assertSame('manual_review',$this->records->order($id)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:id',['id'=>$id]));
        $service->releasePaidReview($admin,$id,'Late settlement accepted after review.');
        self::assertSame('fulfilled',$this->records->order($id)['state']);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:id',['id'=>$id]));
    }

    public function testBankReceiptAfterAutomaticCancellationIsPreservedForReview(): void
    {
        $id=$this->place(); $this->records->selectPaymentMethod($id,'eft'); $this->clock->modify('+8 days');
        self::assertTrue($this->orders->cancelDue($id));
        $service=new PaymentAdministrationService($this->records,new TransactionManager($this->db),$this->payments,$this->fulfilment,IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $service->confirmBank($this->financeAdmin(),$id,Uuid::v4(),12345,'2026-09-20T11:00:00+02:00','BANK-CANCELLED-'.Uuid::v4(),'Receipt arrived after order cancellation.');
        self::assertSame('manual_review',$this->records->order($id)['state']);
        self::assertSame(1,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_manual_payment_evidence WHERE order_id=:id',['id'=>$id]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:id',['id'=>$id]));
    }

    public function testPartialThenFullRefundCreditsFundsAndRevokesOnlyAfterFullItemRefund(): void
    {
        $id=$this->place(); $this->payments->purchase($this->actor,$id,Uuid::v4(),'demo_success');
        $item=$this->records->items($id)[0];
        $service=new RefundAdministrationService($this->records,new TransactionManager($this->db),IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $admin=$this->financeAdmin(); $key=Uuid::v4();
        $first=$service->approve($admin,$id,(int)$item['id'],$key,'goodwill','Partial goodwill remedy.',1,2345);
        self::assertSame($first,$service->approve($admin,$id,(int)$item['id'],$key,'goodwill','Partial goodwill remedy.',1,2345));
        self::assertSame('partially_refunded',$this->records->order($id)['state']);
        self::assertSame('awaiting_activation',$this->db->fetchOne('SELECT state FROM commerce_entitlements WHERE order_item_id=:item',['item'=>$item['id']]));
        $service->approve($admin,$id,(int)$item['id'],Uuid::v4(),'service_failure','Full remaining remedy.',1,10000);
        self::assertSame('refunded',$this->records->order($id)['state']);
        self::assertSame('revoked',$this->db->fetchOne('SELECT state FROM commerce_entitlements WHERE order_item_id=:item',['item'=>$item['id']]));
        self::assertSame(12345,(int)$this->db->fetchOne('SELECT SUM(amount_minor) FROM commerce_fund_entries WHERE account_id IN (SELECT id FROM commerce_fund_accounts WHERE owner_user_id=:user)',['user'=>$this->actor->id]));
        self::assertSame(2,(int)$this->db->fetchOne("SELECT COUNT(*) FROM commerce_documents WHERE order_id=:id AND kind='credit_note'",['id'=>$id]));
        self::assertStringStartsWith('%PDF-',(new InvoicePdfRenderer($this->records))->render($this->records->documents($id)[2]));
    }
}
