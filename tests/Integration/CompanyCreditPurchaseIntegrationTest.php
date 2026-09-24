<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\{AccessService,CompanyCreditFulfilment,CompanyCreditPurchaseService,FulfilmentService,OrderService,PaymentService,PaymentAdministrationService,RefundAdministrationService,CommerceMaintenance};
use CattoLearning\Commerce\Infrastructure\{CommerceRepository,InvoicePdfRenderer};
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,CompanyRepository,Database,TransactionManager};
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{DevelopmentFixture,FakeMailer,IntegrationContainer};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

#[Group('commerce')]
final class CompanyCreditPurchaseIntegrationTest extends TestCase
{
    private Database $db;
    private DevelopmentFixture $fixture;
    private CurrentUser $buyer;
    private int $companyId;
    private int $courseId;
    private int $variantId;
    private int $providerUserId;
    private int $providerCompanyId;
    private CommerceRepository $records;
    private OrderService $orders;
    private AccessService $access;
    private CompanyCreditPurchaseService $purchases;
    private PaymentService $payments;
    private MockClock $clock;

    protected function setUp(): void
    {
        $container=IntegrationContainer::get();
        $this->db=IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture=new DevelopmentFixture($this->db);
        $suffix=$this->fixture->suffix();
        $buyerId=$this->fixture->createUser('Credit buyer '.$suffix,'credit-buyer-'.$suffix.'@example.test');
        $this->companyId=$this->fixture->createCompany($buyerId,'Credit buyer '.$suffix,'credit-buyer-'.$suffix.'.example.test');
        $this->addCompanyMember($buyerId,'owner');
        $providerId=$this->fixture->createUser('Credit provider '.$suffix,'credit-provider-'.$suffix.'@example.test');
        $this->providerUserId=$providerId;
        $providerIdCompany=$this->fixture->createCompany($providerId,'Credit provider '.$suffix,'credit-provider-'.$suffix.'.example.test');
        $this->providerCompanyId=$providerIdCompany;
        $this->courseId=$this->fixture->createCourse($providerId,$providerIdCompany,'credit-course-'.$suffix,'Credit course','published');
        $this->variantId=$this->variant(86400,12345);
        $this->buyer=new CurrentUser($buyerId,Uuid::v4(),'credit-buyer-'.$suffix.'@example.test','Credit buyer',[],
            ['COMPANY.CREDIT.MANAGE','COMPANY.REQUEST.MANAGE','COMMERCE.CHECKOUT.START','COMMERCE.ORDER.VIEW'],Uuid::v4());
        $this->clock=new MockClock('2026-09-23T12:00:00+02:00');
        $this->records=new CommerceRepository($this->db);
        $tx=new TransactionManager($this->db);
        $policy=new CommercePolicy(dirname(__DIR__,2));
        $transitions=$container->get(TransitionService::class);
        $this->orders=new OrderService($this->records,$tx,$policy,$transitions,$this->clock);
        $this->access=new AccessService($this->records,$tx,$transitions,$this->clock);
        $companyFulfilment=new CompanyCreditFulfilment($this->records,$container->get(AdministrationRepository::class),$container->get(CourseRepository::class),$this->clock);
        $fulfilment=new FulfilmentService($this->records,$transitions,$this->access,$tx,$this->orders,$this->clock,$companyFulfilment);
        $this->payments=new PaymentService($this->records,$tx,$this->orders,new OmnipayPaymentGatewayAdapter('test',$this->clock),$fulfilment,$transitions,$this->clock);
        $this->purchases=new CompanyCreditPurchaseService($this->records,$container->get(CompanyRepository::class),
            $container->get(AdministrationRepository::class),$tx,$policy,$transitions,$this->payments,$this->clock);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['company_credit_basket']);
    }

    private function variant(int $seconds, int $price, ?int $courseId = null): int
    {
        $courseId ??= $this->courseId;
        return (int)$this->db->fetchOne(
            'INSERT INTO course_price_variants(public_id,course_id,access_period_seconds,price_minor_units,currency_code,position,created_by_user_id,updated_by_user_id) VALUES (:public,:course,:period,:price,\'ZAR\',:position,:user,:user) RETURNING id',
            ['public'=>Uuid::v4(),'course'=>$courseId,'period'=>$seconds,'price'=>$price,
             'position'=>1+(int)$this->db->fetchOne('SELECT COALESCE(MAX(position),0) FROM course_price_variants WHERE course_id=:course',['course'=>$courseId]),
             'user'=>$this->providerUserId]
        );
    }

    private function addCompanyMember(int $userId, string $role = 'member'): void
    {
        $this->db->executeStatement("INSERT INTO company_users(company_id,user_id,company_role,status) VALUES (:company,:user,:role,'active')",
            ['company'=>$this->companyId,'user'=>$userId,'role'=>$role]);
    }

    public function testMultiVariantCompanyPurchaseCreatesOnlyPaidHistoricalCreditLots(): void
    {
        $second=$this->variant(172800,22000);
        $anotherCourse=$this->fixture->createCourse($this->providerUserId,$this->providerCompanyId,'credit-other-'.$this->fixture->suffix(),'Another course','published');
        $third=$this->variant(86400,5000,$anotherCourse);
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,3);
        $this->purchases->add($this->buyer,$this->companyId,$second,2);
        $this->purchases->add($this->buyer,$this->companyId,$third,1);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        self::assertCount(3,$review['items']);
        self::assertSame(86035,$review['total_minor']);
        $id=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true);
        self::assertSame('fulfilled',$this->records->order($id)['state']);
        self::assertSame(3,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$id]));
        self::assertSame([1,2,3],array_map('intval',$this->db->fetchFirstColumn('SELECT quantity FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id) ORDER BY quantity',['id'=>$id])));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:id',['id'=>$id]));
        self::assertSame($id,$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true));
        self::assertCount(1,$this->records->payments($id));
    }

    public function testChangedPriceInvalidatesReviewAndForeignCompanyIsRejected(): void
    {
        $otherCompany=$this->fixture->createCompany($this->buyer->id,'Foreign company '.$this->fixture->suffix(),'foreign-'.$this->fixture->suffix().'.example.test');
        try {
            $this->purchases->add($this->buyer,$otherCompany,$this->variantId,1);
            self::fail('Foreign company purchase must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('company you administer',$exception->getMessage());
        }
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,1);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=price_minor_units+1 WHERE id=:id',['id'=>$this->variantId]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credits or prices changed');
        $this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true);
    }

    public function testUnpaidOrFailedCompanyOrderCreatesNoCredit(): void
    {
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,1);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $id=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','eft','',false,true);
        self::assertSame('awaiting_payment',$this->records->order($id)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$id]));
        $item=$this->records->items($id)[0];
        $this->db->executeStatement('SAVEPOINT unpaid_credit_guard');
        try {
            $this->db->executeStatement("INSERT INTO course_credits(public_id,company_id,course_id,access_period_seconds,quantity,source_type,commerce_order_item_id) VALUES (:public,:company,:course,:period,1,'purchase',:item)",
                ['public'=>Uuid::v4(),'company'=>$this->companyId,'course'=>$this->courseId,'period'=>86400,'item'=>$item['id']]);
            self::fail('The database must reject purchased credits before paid settlement.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT unpaid_credit_guard');
            self::assertStringContainsString('matching fully paid company order item',$exception->getMessage());
        }
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,1);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $failed=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_failure',false,true);
        self::assertSame('awaiting_payment',$this->records->order($failed)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$failed]));
    }

    public function testRequestApprovalPurchasesExactCreditThenEnrolsAndQueuesLearnerNotice(): void
    {
        $suffix=$this->fixture->suffix();
        $learner=$this->fixture->createUser('Credit learner '.$suffix,'credit-learner-'.$suffix.'@example.test');
        $this->addCompanyMember($learner);
        $administration=IntegrationContainer::get()->get(AdministrationRepository::class);
        $request=$administration->createRequest($learner,$this->companyId,$this->courseId,86400,'Please enrol me.');
        $service=IntegrationContainer::get()->get(PlatformAdministrationService::class);
        self::assertSame('purchase_required',$service->decideRequest($request,true,'Approved',$this->buyer->id,$this->companyId)['decision']);
        self::assertSame('pending',$this->db->fetchOne('SELECT status FROM course_requests WHERE id=:id',['id'=>$request]));
        self::assertNull($this->purchases->startForRequest($this->buyer,$this->companyId,$request));
        $review=$this->purchases->review($this->buyer,$this->companyId);
        self::assertSame($request,$review['request_id']);
        self::assertSame($this->variantId,$review['items'][0]['variant_id']);
        $order=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true);
        self::assertSame('fulfilled',$this->records->order($order)['state']);
        $row=$this->db->fetchAssociative('SELECT cr.status,cr.enrolment_id,ca.credit_id,cc.commerce_order_item_id FROM course_requests cr JOIN course_credit_allocations ca ON ca.enrolment_id=cr.enrolment_id JOIN course_credits cc ON cc.id=ca.credit_id WHERE cr.id=:id',['id'=>$request]);
        self::assertIsArray($row);
        self::assertSame('fulfilled',$row['status']);
        self::assertGreaterThan(0,(int)$row['enrolment_id']);
        self::assertNotNull($row['commerce_order_item_id']);
        $mailer=new FakeMailer();
        $maintenance=new CommerceMaintenance($this->records,$this->orders,$this->access,new InvoicePdfRenderer($this->records),$mailer,new TransactionManager($this->db),$this->clock);
        $maintenance->deliverCompanyCourseNotices(2);
        self::assertSame(['course_request_decision','course_enrolment_notice'],array_column($mailer->messages,'type'));
    }

    public function testRequestChangedBeforeSettlementHoldsPaymentForReviewWithoutIssuingCredits(): void
    {
        $suffix=$this->fixture->suffix();
        $learner=$this->fixture->createUser('Pending learner '.$suffix,'pending-'.$suffix.'@example.test');
        $this->addCompanyMember($learner);
        $request=IntegrationContainer::get()->get(AdministrationRepository::class)->createRequest($learner,$this->companyId,$this->courseId,86400,'Please enrol me.');
        $this->purchases->startForRequest($this->buyer,$this->companyId,$request);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        try {
            $this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','eft','',false,true);
            self::fail('A request-linked purchase must use immediate payment.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('immediate confirmed payment',$exception->getMessage());
        }
        $order=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_failure',false,true);
        $this->db->executeStatement("UPDATE course_requests SET status='rejected' WHERE id=:id",['id'=>$request]);
        $this->payments->purchase($this->buyer,$order,Uuid::v4(),'demo_success');
        self::assertSame('manual_review',$this->records->order($order)['state']);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$order]));
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_enrolments WHERE user_id=:user AND course_id=:course',['user'=>$learner,'course'=>$this->courseId]));
        $item=$this->records->items($order)[0];
        $refunds=new RefundAdministrationService($this->records,new TransactionManager($this->db),IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $refunds->approve($this->financeAdmin(),$order,(int)$item['id'],Uuid::v4(),'service_failure','Request changed before fulfilment; refund the unissued credit.',1,0);
        self::assertSame('refunded',$this->records->order($order)['state']);
        self::assertSame((int)$item['amount_minor'],(int)$this->db->fetchOne('SELECT SUM(amount_minor) FROM commerce_fund_entries WHERE account_id IN (SELECT id FROM commerce_fund_accounts WHERE owner_company_id=:company)',['company'=>$this->companyId]));
    }

    private function financeAdmin(): CurrentUser
    {
        return new CurrentUser($this->buyer->id,Uuid::v4(),$this->buyer->primaryEmail,'Finance ADMIN',[],
            ['PLATFORM.ORDER.VIEW','PLATFORM.PAYMENT.MANAGE','PLATFORM.PAYMENT.RECONCILE','PLATFORM.REFUND.MANAGE'],Uuid::v4());
    }

    public function testConfirmedBankCompanyOrderIssuesCreditsOnlyAfterEvidence(): void
    {
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,2);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $id=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','eft','',false,true);
        self::assertSame(0,(int)$this->db->fetchOne('SELECT COUNT(*) FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$id]));
        $service=new PaymentAdministrationService($this->records,new TransactionManager($this->db),$this->payments,
            new FulfilmentService($this->records,IntegrationContainer::get()->get(TransitionService::class),$this->access,new TransactionManager($this->db),$this->orders,$this->clock,
                new CompanyCreditFulfilment($this->records,IntegrationContainer::get()->get(AdministrationRepository::class),IntegrationContainer::get()->get(CourseRepository::class),$this->clock)),
            IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        $service->confirmBank($this->financeAdmin(),$id,Uuid::v4(),24690,'2026-09-23T11:00:00+02:00','BANK-COMPANY-'.Uuid::v4(),'Matched company bank receipt.');
        self::assertSame('fulfilled',$this->records->order($id)['state']);
        self::assertSame(2,(int)$this->db->fetchOne('SELECT quantity FROM course_credits WHERE commerce_order_item_id IN (SELECT id FROM commerce_order_items WHERE order_id=:id)',['id'=>$id]));
    }

    public function testCompanyUnusedRefundUsesNewestLotAndHistoricalPrice(): void
    {
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,2);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $older=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true);
        $this->db->executeStatement('UPDATE course_price_variants SET price_minor_units=15000 WHERE id=:id',['id'=>$this->variantId]);
        $this->purchases->add($this->buyer,$this->companyId,$this->variantId,2);
        $review=$this->purchases->review($this->buyer,$this->companyId);
        $newer=$this->purchases->place($this->buyer,$this->companyId,$review['purchase_key'],$review['quote'],'Company address','dummy','demo_success',false,true);
        $oldItem=$this->records->items($older)[0]; $newItem=$this->records->items($newer)[0];
        $service=new RefundAdministrationService($this->records,new TransactionManager($this->db),IntegrationContainer::get()->get(TransitionService::class),$this->clock);
        try {
            $service->approve($this->financeAdmin(),$older,(int)$oldItem['id'],Uuid::v4(),'voluntary','Unused credit refund.',1,0);
            self::fail('LIFO must reject the older eligible lot.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('newest eligible',$error->getMessage());
        }
        $service->approve($this->financeAdmin(),$newer,(int)$newItem['id'],Uuid::v4(),'voluntary','Unused credit refund.',1,0);
        self::assertSame(15000,$this->records->refundedAmount($newer));
        self::assertSame(1,(int)$this->db->fetchOne('SELECT refunded_quantity FROM course_credits WHERE commerce_order_item_id=:item',['item'=>$newItem['id']]));
        self::assertSame(15000,(int)$this->db->fetchOne('SELECT SUM(e.amount_minor) FROM commerce_fund_entries e JOIN commerce_fund_accounts a ON a.id=e.account_id WHERE a.owner_company_id=:company',['company'=>$this->companyId]));
        $creditId=(int)$this->db->fetchOne('SELECT id FROM course_credits WHERE commerce_order_item_id=:item',['item'=>$newItem['id']]);
        $this->db->executeStatement("INSERT INTO course_credit_allocations(credit_id,user_id,status) VALUES (:credit,:user,'assigned')",['credit'=>$creditId,'user'=>$this->buyer->id]);
        $this->db->executeStatement('SAVEPOINT refunded_unit_guard');
        try {
            $this->db->executeStatement("INSERT INTO course_credit_allocations(credit_id,user_id,status) VALUES (:credit,:user,'assigned')",['credit'=>$creditId,'user'=>$this->buyer->id]);
            self::fail('Refunded credit units must not be allocatable.');
        } catch (\Doctrine\DBAL\Exception $error) {
            $this->db->executeStatement('ROLLBACK TO SAVEPOINT refunded_unit_guard');
            self::assertStringContainsString('No unrefunded credit units',$error->getMessage());
        }
    }
}
