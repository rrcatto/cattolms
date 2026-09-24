<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

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
        $list=$this->twig()->render('@platform/pages/admin-commerce-orders.html.twig',$base+[
            'orders'=>[['id'=>12,'state'=>'awaiting_payment','company_id'=>null,'purchaser_name'=>'Buyer','purchaser_email'=>'buyer@example.test','placed_at'=>'2026-09-23T10:00:00+02:00','total_label'=>'R 123.45']],
            'search'=>'buyer@example.test']);
        self::assertStringContainsString('/admin/commerce/orders/12',$list);
        self::assertStringContainsString('method="get"',$list);

        $item=['id'=>8,'title'=>'Course','access_period_seconds'=>86400,'quantity'=>1,'product_type'=>'individual_access','amount_label'=>'R 123.45','amount_minor'=>12345,'refunded_minor'=>0,'remaining_label'=>'R 123.45','latest_refundable'=>null,'unissued'=>false,'refund_key'=>'00000000-0000-4000-8000-000000000002'];
        $detail=$this->twig()->render('@platform/pages/admin-commerce-order.html.twig',$base+[
            'order'=>['id'=>12,'state'=>'awaiting_payment','total_minor'=>12345,'currency'=>'ZAR','company_id'=>null,'placed_at'=>'2026-09-23T10:00:00+02:00','payment_due_at'=>'2026-09-30T10:00:00+02:00'],
            'snapshot'=>['billing_name'=>'Buyer'],'items'=>[$item],'payments'=>[],'refunds'=>[],'documents'=>[],
            'total_label'=>'R 123.45','fund_balance_label'=>'R 0.00','payment_method'=>'eft','can_confirm'=>true,'can_reconcile'=>true,'can_refund'=>true,'can_view_payments'=>true,
            'confirmation_key'=>'00000000-0000-4000-8000-000000000001']);
        self::assertStringContainsString('/admin/commerce/orders/12/confirm-bank',$detail);
        self::assertStringContainsString('name="bank_reference"',$detail);
        self::assertStringContainsString('name="csrf"',$detail);
    }
}
