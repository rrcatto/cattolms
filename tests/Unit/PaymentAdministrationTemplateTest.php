<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\BillingFixture;
use CattoLearning\View\Twig\PlatformUiExtension;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

final class PaymentAdministrationTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        $files=new FilesystemLoader();
        $files->addPath(dirname(__DIR__,2).'/resources/views','platform');
        $twig=new Environment(new ChainLoader([new ArrayLoader(['test-layout'=>'{% block page_body %}{% endblock %}']),$files]),['strict_variables'=>true]);
        $twig->addExtension(new PlatformUiExtension(new PlatformUi()));
        return $twig;
    }

    public function testAdminOrderPagesRenderNativeProtectedFinancialForms(): void
    {
        $base=['layout'=>'test-layout','platform'=>['icon_sprite'=>'/icons.svg'],'app'=>['name'=>'Test LMS'],'csrf'=>'test-csrf'];
        $sort=\CattoLearning\Support\SortOrder::create(null,null,['placed'],'placed',\CattoLearning\Support\SortOrder::DESCENDING);
        $list=$this->twig()->render('@platform/pages/admin-commerce-orders.html.twig',$base+[
            'rows'=>[['id'=>12,'number'=>'CL-00000012','href'=>'/admin/commerce/orders/12','when'=>'23 Sep 2026 10:00','state'=>'awaiting_payment','state_label'=>'Awaiting payment','company_id'=>null,'company_name'=>null,'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test','total_label'=>'R 123.45','paid_label'=>'R 0.00']],
            'filters'=>['from'=>'','to'=>'','state'=>'','account'=>'','q'=>'buyer@example.test'],'states'=>\CattoLearning\Commerce\Application\MoneyRecords::ORDER_STATES,'filtered'=>true,
            'orders_pagination'=>\CattoLearning\Application\PlatformAdministrationService::paginationPayload('orders',\CattoLearning\Support\Pagination::create(1,50,1,50),'/admin/commerce/orders','Orders',['q'=>'buyer@example.test'])]
            +\CattoLearning\Application\PlatformAdministrationService::sortViewFor('orders',$sort,'/admin/commerce/orders',['placed'=>'Placed','order'=>'Order','actions'=>''],['placed'],['q'=>'buyer@example.test']));
        self::assertStringContainsString('/admin/commerce/orders/12',$list);
        self::assertStringContainsString('method="get"',$list);

        $item=['quotes'=>[],'refundable_quantity'=>1,'id'=>8,'title'=>'Course','access_period_seconds'=>86400,'quantity'=>1,'product_type'=>'individual_access','amount_label'=>'R 123.45','amount_minor'=>12345,'paid_minor'=>12345,'paid_label'=>'R 123.45','discount_label'=>null,'refunded_minor'=>0,'refunded_label'=>'R 0.00','remaining_label'=>'R 123.45','is_bundle'=>false,'includes'=>'','grants'=>[],'latest_refundable'=>null,'unissued'=>false,'refund_key'=>'00000000-0000-4000-8000-000000000002','rule_refusals'=>[]];
        $detail=$this->twig()->render('@platform/pages/admin-commerce-order.html.twig',$base+[
            'order'=>['id'=>12,'state'=>'awaiting_payment','total_minor'=>12345,'currency'=>'ZAR','company_id'=>null,'placed_at'=>'2026-09-23T10:00:00+02:00','payment_due_at'=>'2026-09-30T10:00:00+02:00'],
            'snapshot'=>['billing'=>BillingFixture::person('Buyer Billing')->toSnapshot(),'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test'],
            'billing_lines'=>BillingFixture::person('Buyer Billing')->documentLines(),'items'=>[$item],'payments'=>[],'refunds'=>[],'documents'=>[],
            'total_label'=>'R 123.45','totals'=>[['label'=>'Total','value'=>'R 123.45']],'promotion_eligible_label'=>null,'fund_balance_label'=>'R 0.00','payment_method'=>'eft','can_confirm'=>true,'can_reconcile'=>true,'can_refund'=>true,'can_view_payments'=>true,'wallet_balance_label'=>'R 0.00','wallet_href'=>'/admin/wallets/person/5','account_reference'=>'CL-20261017-0001-U','due_minor'=>12345,'due_label'=>'R 123.45','paid_label'=>'R 0.00','refund_window'=>'7 days',
            'confirmation_key'=>'00000000-0000-4000-8000-000000000001']);
        self::assertStringContainsString('/admin/commerce/orders/12/confirm-bank',$detail);
        self::assertStringContainsString('name="bank_reference"',$detail);
        self::assertStringContainsString('name="csrf"',$detail);
        self::assertStringContainsString('Buyer Billing<br>Nkosi Consulting<br>14 Jacaranda Avenue',$detail,'The order shows its snapshot billing lines.');
        self::assertStringContainsString('buyer@example.test',$detail,'The purchaser is shown apart from the billed party.');
    }

    public function testTheRefundFormAsksForAnOverrideReasonOnlyOutsideTheRefundPolicy(): void
    {
        $base=['layout'=>'test-layout','platform'=>['icon_sprite'=>'/icons.svg'],'app'=>['name'=>'Test LMS'],'csrf'=>'test-csrf'];
        $item=['quotes'=>[],'refundable_quantity'=>1,'id'=>8,'title'=>'Course','access_period_seconds'=>86400,'quantity'=>1,'product_type'=>'individual_access','amount_label'=>'R 123.45','amount_minor'=>12345,'paid_minor'=>12345,'paid_label'=>'R 123.45','discount_label'=>null,'refunded_minor'=>0,'refunded_label'=>'R 0.00','remaining_label'=>'R 123.45','is_bundle'=>false,'includes'=>'','grants'=>[],'latest_refundable'=>null,'unissued'=>false,'refund_key'=>'00000000-0000-4000-8000-000000000002'];
        $refund=['id'=>3,'amount_label'=>'R 100.00','quantity'=>1,'basis'=>'goodwill','actor_name'=>'Finance ADMIN','approved_at'=>'2026-10-01T10:00:00+02:00','reason'=>'Customer request','destination'=>'wallet','net_label'=>'R 100.00','wallet_part_minor'=>0,'wallet_part_label'=>'R 0.00','deduction_lines'=>[],'to_pay'=>false,'payout_due_at'=>null,'payout_paid_at'=>null,'payout_reference'=>null,'payout_instruction'=>''];
        $render=fn(array $refusals,array $refunds): string => $this->twig()->render('@platform/pages/admin-commerce-order.html.twig',$base+[
            'order'=>['id'=>12,'state'=>'fulfilled','total_minor'=>12345,'currency'=>'ZAR','company_id'=>null,'placed_at'=>'2026-09-23T10:00:00+02:00','payment_due_at'=>'2026-09-30T10:00:00+02:00'],
            'snapshot'=>['billing'=>BillingFixture::person('Buyer Billing')->toSnapshot(),'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test'],
            'billing_lines'=>BillingFixture::person('Buyer Billing')->documentLines(),'items'=>[$item+['rule_refusals'=>$refusals]],'payments'=>[],'refunds'=>$refunds,'documents'=>[],
            'total_label'=>'R 123.45','totals'=>[['label'=>'Total','value'=>'R 123.45']],'promotion_eligible_label'=>null,'fund_balance_label'=>'R 0.00','payment_method'=>'dummy','can_confirm'=>true,'can_reconcile'=>true,'can_refund'=>true,'can_view_payments'=>true,'wallet_balance_label'=>'R 0.00','wallet_href'=>'/admin/wallets/person/5','account_reference'=>'CL-20261017-0001-U','due_minor'=>12345,'due_label'=>'R 123.45','paid_label'=>'R 0.00','refund_window'=>'7 days',
            'confirmation_key'=>'00000000-0000-4000-8000-000000000001']);

        $inside=$render([],[$refund+['policy_refusal'=>null,'override_reason'=>null]]);
        self::assertStringContainsString('Within the Refund Policy',$inside);
        self::assertStringNotContainsString('name="override_reason"',$inside);
        self::assertStringNotContainsString('Refund Policy override',$inside);
        self::assertStringContainsString('Approve refund and issue credit note',$inside);
        self::assertStringContainsString('gives no refund once a course has started',$inside,'The rule is stated where refunds are approved');
        self::assertStringContainsString('refundable within 7 days of the purchase',$inside,'with the refund window in force.');
        self::assertStringContainsString('name="destination"',$inside,'ADMIN chooses wallet credit or money back.');

        $outside=$render(['The course started on 1 October 2026. A started course is not refunded.'],[$refund+['policy_refusal'=>'7 days have passed since the purchase on 23 September 2026.','override_reason'=>'The request reached us in time.']]);
        self::assertStringContainsString('Outside the Refund Policy',$outside);
        self::assertStringContainsString('The course started on 1 October 2026. A started course is not refunded.',$outside);
        self::assertMatchesRegularExpression('/<textarea id="refund-override-8" name="override_reason" minlength="5" maxlength="2000" required/',$outside);
        self::assertStringContainsString('Override the Refund Policy and approve refund',$outside);
        self::assertStringContainsString('Refund Policy override',$outside,'An approved refund says an override was used');
        self::assertStringContainsString('The Refund Policy refused this refund: 7 days have passed since the purchase on 23 September 2026. Override reason: The request reached us in time.',$outside,'and why.');
    }
}
