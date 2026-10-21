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
        $twig->addExtension(new \CattoLearning\View\Twig\PlatformExtension());
        return $twig;
    }

    public function testAdminOrderPagesRenderNativeProtectedFinancialForms(): void
    {
        $base=['layout'=>'test-layout','platform'=>['icon_sprite'=>'/icons.svg'],'app'=>['name'=>'Test LMS'],'csrf'=>'test-csrf','today'=>'2026-10-18','story'=>self::story()];
        $sort=\CattoLearning\Support\SortOrder::create(null,null,['placed'],'placed',\CattoLearning\Support\SortOrder::DESCENDING);
        $list=$this->twig()->render('@platform/pages/admin-commerce-orders.html.twig',$base+[
            'rows'=>[['id'=>12,'number'=>'CL-00000012','href'=>'/admin/commerce/orders/12','when'=>'23 Sep 2026 10:00','state'=>'awaiting_payment','state_label'=>'Awaiting payment','company_id'=>null,'company_name'=>null,'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test','total_label'=>'R 123.45','paid_label'=>'R 0.00',
                'reference_label'=>'CL-20261017-0001-U','bought'=>'Course','status_label'=>'Waiting for payment','status_note'=>'R 123.45 due by EFT','status_tone'=>'warning',
                'purchaser_person'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'buyer@example.test']]],
            'filters'=>['from'=>'','to'=>'','state'=>'','account'=>'','q'=>'buyer@example.test'],'states'=>\CattoLearning\Commerce\Application\MoneyRecords::ORDER_STATES,'filtered'=>true,
            'orders_pagination'=>\CattoLearning\Application\PlatformAdministrationService::paginationPayload('orders',\CattoLearning\Support\Pagination::create(1,50,1,50),'/admin/commerce/orders','Orders',['q'=>'buyer@example.test'])]
            +\CattoLearning\Application\PlatformAdministrationService::sortViewFor('orders',$sort,'/admin/commerce/orders',['placed'=>'Placed','order'=>'Order','actions'=>''],['placed'],['q'=>'buyer@example.test']));
        self::assertStringContainsString('/admin/commerce/orders/12',$list);
        self::assertStringContainsString('method="get"',$list);
        self::assertLessThan(strpos($list,'CL-00000012'),strpos($list,'CL-20261017-0001-U'),'A row names the account first.');
        // A table names the buyer by their name, linked to their profile, under the account reference it always showed
        // (owner, 2026-10-21: no email and no reference added to a table).
        self::assertStringContainsString('<strong>CL-20261017-0001-U</strong><br><span class="small"><span class="cl-ui-person"><a class="cl-ui-person-account" href="/admin/people/5"><span class="cl-ui-person-name">Buyer</span></a></span></span>',$list);
        self::assertStringNotContainsString('mailto:',$list);
        self::assertStringContainsString('R 123.45 due by EFT',$list,'and where the order stands, in words.');

        $item=['quotes'=>[],'refundable_quantity'=>1,'id'=>8,'title'=>'Course','access_period_seconds'=>86400,'quantity'=>1,'product_type'=>'individual_access','amount_label'=>'R 123.45','amount_minor'=>12345,'paid_minor'=>12345,'paid_label'=>'R 123.45','discount_label'=>null,'refunded_minor'=>0,'refunded_label'=>'R 0.00','remaining_label'=>'R 123.45','is_bundle'=>false,'includes'=>'','grants'=>[],'latest_refundable'=>null,'unissued'=>false,'refund_key'=>'00000000-0000-4000-8000-000000000002','rule_refusals'=>[]];
        $detail=$this->twig()->render('@platform/pages/admin-commerce-order.html.twig',$base+[
            'order'=>['id'=>12,'state'=>'awaiting_payment','total_minor'=>12345,'currency'=>'ZAR','company_id'=>null,'placed_at'=>'2026-09-23T10:00:00+02:00','payment_due_at'=>'2026-09-30T10:00:00+02:00'],
            'snapshot'=>['billing'=>BillingFixture::person('Buyer Billing')->toSnapshot(),'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test'],
            'billing_lines'=>BillingFixture::person('Buyer Billing')->documentLines(),'items'=>[$item],'payments'=>[],'refunds'=>[],'documents'=>[],
            'total_label'=>'R 123.45','totals'=>[['label'=>'Total','value'=>'R 123.45']],'promotion_eligible_label'=>null,'fund_balance_label'=>'R 0.00','payment_method'=>'eft','can_confirm'=>true,'can_reconcile'=>true,'can_refund'=>true,'can_view_payments'=>true,'wallet_balance_label'=>'R 0.00','wallet_href'=>'/admin/wallets/person/5','account_reference'=>'CL-20261017-0001-U','purchaser'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'buyer@example.test'],'due_minor'=>12345,'due_label'=>'R 123.45','paid_label'=>'R 0.00','refund_window'=>'7 days',
            'confirmation_key'=>'00000000-0000-4000-8000-000000000001']);
        self::assertStringContainsString('/admin/commerce/orders/12/confirm-bank',$detail);
        // What happened comes first, in sentences, with what to do next (owner, 2026-10-18).
        self::assertLessThan(strpos($detail,'Record the EFT payment'),strpos($detail,'Buyer ordered Course for R 123.45'),'The story comes before the form.');
        self::assertStringContainsString('Waiting for the EFT payment',$detail);
        self::assertStringContainsString('<span class="cl-ui-person cl-ui-person--full"><span class="cl-ui-person-line"><a class="cl-ui-person-account" href="/admin/people/5"><span class="cl-ui-person-name">Buyer</span></a></span><span class="cl-ui-person-line cl-ui-person-reference">CL-20261017-0001-U</span>',$detail,'The page head names the account first: the buyer, linked to their account.');
        self::assertStringContainsString('type="date" name="received_on"',$detail,'The day the money arrived is picked from a calendar.');
        self::assertStringNotContainsString('received_at',$detail,'No timestamp with a time zone is typed.');
        self::assertStringContainsString('name="bank_reference"',$detail);
        self::assertStringContainsString('name="csrf"',$detail);
        self::assertStringContainsString('Buyer Billing<br>Nkosi Consulting<br>14 Jacaranda Avenue',$detail,'The order shows its snapshot billing lines.');
        self::assertStringContainsString('buyer@example.test',$detail,'The purchaser is shown apart from the billed party.');
        // The order's records together, each linking to its own page (owner, 2026-10-19).
        self::assertStringContainsString('<a href="/admin/commerce/payment-events/9">',$detail);
        self::assertStringContainsString('<a href="/admin/commerce/trail/7">',$detail);
        // Each person a sentence names is a person, linked to their account (owner, 2026-10-21).
        // Below the page head the buyer is their name alone, linked (owner, 2026-10-21: the details once on a page).
        self::assertStringContainsString('<span class="cl-ui-person"><a class="cl-ui-person-account" href="/admin/people/5"><span class="cl-ui-person-name">Buyer</span></a></span> ordered Course',$detail);
        self::assertSame(1,substr_count($detail,'CL-20261017-0001-U</span>'),'The account reference is given once.');
        self::assertLessThan(strpos($detail,'<h3 class="h5">Commerce Trail</h3>'),strpos($detail,'<h3 class="h5">Payment Events</h3>'));
        self::assertLessThan(strpos($detail,'<h3 class="h5">Payment Events</h3>'),strpos($detail,'<h3 class="h5">Money Ledger</h3>'));
    }

    /** @return array<string,mixed> what OrderStory hands the order page */
    private static function story(): array
    {
        return ['summary'=>['Buyer ordered Course for R 123.45 on 23 Sep 2026 at 10:00.','Nothing has been paid yet.'],
            'summary_parts'=>[[['person'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'']],['text'=>' ordered Course for R 123.45 on 23 Sep 2026 at 10:00.']],[['text'=>'Nothing has been paid yet.']]],
            'status'=>['label'=>'Waiting for payment','note'=>'R 123.45 due by EFT','tone'=>'warning'],
            'next'=>['tone'=>'warning','heading'=>'Waiting for the EFT payment','text'=>'When R 123.45 shows on your bank statement, record it below.','parts'=>[['text'=>'When R 123.45 shows on your bank statement, record it below.']]],
            'timeline'=>[['when'=>'23 Sep 2026 at 10:00','text'=>'Buyer placed order CL-00000012 for R 123.45.','text_parts'=>[['person'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'']],['text'=>' placed order CL-00000012 for R 123.45.']],'by'=>'Buyer','by_person'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>''],'href'=>'/admin/commerce/trail/7']],'ledger'=>[],
            'payment_events'=>[['when'=>'23 Sep 2026 at 10:05','sentence'=>'Buyer\'s card payment of R 123.45 for order CL-00000012 went through: the money was received.',
                'sentence_parts'=>[['person'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'']],['text'=>'\'s card payment of R 123.45 for order CL-00000012 went through: the money was received.']],'amount'=>'R 123.45','href'=>'/admin/commerce/payment-events/9']],
            'fees_label'=>'','refunded_label'=>''];
    }

    public function testTheRefundFormAsksForAnOverrideReasonOnlyOutsideTheRefundPolicy(): void
    {
        $base=['layout'=>'test-layout','platform'=>['icon_sprite'=>'/icons.svg'],'app'=>['name'=>'Test LMS'],'csrf'=>'test-csrf','today'=>'2026-10-18','story'=>self::story()];
        $item=['quotes'=>[],'refundable_quantity'=>1,'id'=>8,'title'=>'Course','access_period_seconds'=>86400,'quantity'=>1,'product_type'=>'individual_access','amount_label'=>'R 123.45','amount_minor'=>12345,'paid_minor'=>12345,'paid_label'=>'R 123.45','discount_label'=>null,'refunded_minor'=>0,'refunded_label'=>'R 0.00','remaining_label'=>'R 123.45','is_bundle'=>false,'includes'=>'','grants'=>[],'latest_refundable'=>null,'unissued'=>false,'refund_key'=>'00000000-0000-4000-8000-000000000002'];
        $refund=['id'=>3,'amount_label'=>'R 100.00','quantity'=>1,'basis'=>'goodwill','actor_name'=>'Finance ADMIN','actor_person'=>['name'=>'Finance ADMIN','reference'=>'CL202610170002V','href'=>'/admin/people/2','email'=>'finance@example.test'],'approved_at'=>'2026-10-01T10:00:00+02:00','reason'=>'Customer request','destination'=>'wallet','net_label'=>'R 100.00','wallet_part_minor'=>0,'wallet_part_label'=>'R 0.00','deduction_lines'=>[],'to_pay'=>false,'payout_due_at'=>null,'payout_paid_at'=>null,'payout_reference'=>null,'payout_instruction'=>''];
        $render=fn(array $refusals,array $refunds): string => $this->twig()->render('@platform/pages/admin-commerce-order.html.twig',$base+[
            'order'=>['id'=>12,'state'=>'fulfilled','total_minor'=>12345,'currency'=>'ZAR','company_id'=>null,'placed_at'=>'2026-09-23T10:00:00+02:00','payment_due_at'=>'2026-09-30T10:00:00+02:00'],
            'snapshot'=>['billing'=>BillingFixture::person('Buyer Billing')->toSnapshot(),'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test'],
            'billing_lines'=>BillingFixture::person('Buyer Billing')->documentLines(),'items'=>[$item+['rule_refusals'=>$refusals]],'payments'=>[],'refunds'=>$refunds,'documents'=>[],
            'total_label'=>'R 123.45','totals'=>[['label'=>'Total','value'=>'R 123.45']],'promotion_eligible_label'=>null,'fund_balance_label'=>'R 0.00','payment_method'=>'dummy','can_confirm'=>true,'can_reconcile'=>true,'can_refund'=>true,'can_view_payments'=>true,'wallet_balance_label'=>'R 0.00','wallet_href'=>'/admin/wallets/person/5','account_reference'=>'CL-20261017-0001-U','purchaser'=>['name'=>'Buyer','reference'=>'CL202610170001U','href'=>'/admin/people/5','email'=>'buyer@example.test'],'due_minor'=>12345,'due_label'=>'R 123.45','paid_label'=>'R 0.00','refund_window'=>'7 days',
            'confirmation_key'=>'00000000-0000-4000-8000-000000000001']);

        $inside=$render([],[$refund+['policy_refusal'=>null,'override_reason'=>null]]);
        self::assertStringContainsString('Within the Refund Policy',$inside);
        self::assertStringNotContainsString('name="override_reason"',$inside);
        self::assertStringNotContainsString('Override reason:',$inside);
        self::assertStringContainsString('Approve refund and issue credit note',$inside);
        self::assertStringContainsString('refunds a course only before it has started',$inside,'The rule is stated where refunds are approved');
        self::assertStringContainsString('within 7 days of the purchase',$inside,'with the refund window in force.');
        self::assertStringContainsString('name="destination"',$inside,'ADMIN chooses wallet credit or money back.');

        $outside=$render(['The course started on 1 October 2026. A started course is not refunded.'],[$refund+['policy_refusal'=>'7 days have passed since the purchase on 23 September 2026.','override_reason'=>'The request reached us in time.']]);
        self::assertStringContainsString('Outside the Refund Policy',$outside);
        self::assertStringContainsString('The course started on 1 October 2026. A started course is not refunded.',$outside);
        self::assertMatchesRegularExpression('/<textarea id="refund-override-8" name="override_reason" minlength="5" maxlength="2000" required/',$outside);
        self::assertStringContainsString('Override the Refund Policy and approve refund',$outside);
        self::assertStringContainsString('Outside the Refund Policy (7 days have passed since the purchase on 23 September 2026.) · Override reason: The request reached us in time.',$outside,'An approved refund says an override was used, and why.');
    }
}
