<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\PaymentAdministrationService;
use CattoLearning\Commerce\Application\RefundAdministrationService;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Infrastructure\InvoicePdfRenderer;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Core ADMIN financial controls; browser forms provide decisions and bank evidence, never gateway results. */
final class PaymentAdministrationController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly CommerceRepository $records, private readonly PaymentAdministrationService $payments,
        private readonly RefundAdministrationService $refunds, private readonly InvoicePdfRenderer $pdfs)
    { parent::__construct($auth,$view,$requests); }

    #[Route('/admin/commerce/orders',name:'admin_commerce_orders',methods:['GET'])]
    public function orders(): Response
    {
        $this->requirePermission('PLATFORM.ORDER.VIEW');
        $search=mb_substr(trim((string)$this->request()->query->get('search','')),0,120);
        $orders=$this->records->administrationOrders($search);
        foreach ($orders as &$order) $order['total_label']=Money::strictMinorUnits((int)$order['total_minor'],(string)$order['currency'])->format();
        unset($order);
        return $this->render('admin-commerce-orders',['title'=>'Payment administration','active_nav'=>'admin','orders'=>$orders,'search'=>$search]);
    }

    #[Route('/admin/commerce/orders/{id}',name:'admin_commerce_order',requirements:['id'=>'[0-9]+'],methods:['GET'])]
    public function order(): Response
    {
        $actor=$this->requirePermission('PLATFORM.ORDER.VIEW');
        $id=(int)$this->param('id');
        $order=$this->records->order($id);
        $snapshot=CommerceRepository::decode((string)$order['snapshot']);
        $items=$this->records->items($id);
        foreach ($items as &$item) {
            $details=CommerceRepository::decode((string)$item['snapshot']);
            $item['title']=$details['course_title'] ?? 'Course';
            $item['amount_label']=Money::strictMinorUnits((int)$item['amount_minor'],(string)$order['currency'])->format();
            $item['refunded_minor']=$this->records->refundedAmount($id,(int)$item['id']);
            $item['remaining_label']=Money::strictMinorUnits(max(0,(int)$item['amount_minor']-(int)$item['refunded_minor']),(string)$order['currency'])->format();
            $item['unissued']=$order['company_id']!==null && $this->records->purchasedCredit((int)$item['id'])===null;
            $item['latest_refundable']=$order['company_id']!==null ? $this->records->latestRefundableCredit((int)$order['company_id'],(int)$item['course_id'],(int)$item['access_period_seconds']) : null;
            $unit=(int)$item['amount_minor']>0 && (int)$item['quantity']>0 ? intdiv((int)$item['amount_minor'],(int)$item['quantity']) : 0;
            $item['refundable_quantity']=$item['unissued'] && $unit>0 ? (int)$item['quantity']-intdiv((int)$item['refunded_minor'],$unit) : (int)($item['latest_refundable']['available_count'] ?? 0);
            $item['refund_key']=Uuid::v4();
        }
        unset($item);
        $payments=$actor->hasPermission('PLATFORM.PAYMENT.VIEW') ? $this->records->payments($id) : [];
        foreach ($payments as &$payment) $payment['evidence']=$this->records->manualEvidence((string)$payment['id']);
        unset($payment);
        $refunds=$actor->hasPermission('PLATFORM.REFUND.VIEW') ? $this->records->refunds($id) : [];
        foreach ($refunds as &$refund) $refund['amount_label']=Money::strictMinorUnits((int)$refund['amount_minor'],(string)$refund['currency'])->format();
        unset($refund);
        $documents=array_values(array_filter($this->records->documents($id),fn(array $document): bool => match ($document['kind']) {
            'receipt'=>$actor->hasPermission('PLATFORM.PAYMENT.VIEW'),
            'credit_note'=>$actor->hasPermission('PLATFORM.REFUND.VIEW'),
            default=>true,
        }));
        return $this->render('admin-commerce-order',['title'=>'Order CL-'.str_pad((string)$id,8,'0',STR_PAD_LEFT),'active_nav'=>'admin',
            'order'=>$order,'snapshot'=>$snapshot,'items'=>$items,'payments'=>$payments,'refunds'=>$refunds,'documents'=>$documents,
            'total_label'=>Money::strictMinorUnits((int)$order['total_minor'],(string)$order['currency'])->format(),
            'fund_balance_label'=>$actor->hasPermission('PLATFORM.REFUND.VIEW') ? Money::strictMinorUnits($this->records->fundBalance($order['company_id']===null?(int)$order['purchaser_user_id']:null,$order['company_id']===null?null:(int)$order['company_id'],(string)$order['currency']),(string)$order['currency'])->format() : null,
            'payment_method'=>$this->records->paymentMethod($id),'can_confirm'=>$actor->hasPermission('PLATFORM.PAYMENT.MANAGE') && $actor->hasPermission('PLATFORM.PAYMENT.RECONCILE'),
            'can_reconcile'=>$actor->hasPermission('PLATFORM.PAYMENT.RECONCILE'),'can_refund'=>$actor->hasPermission('PLATFORM.REFUND.MANAGE'),
            'can_view_payments'=>$actor->hasPermission('PLATFORM.PAYMENT.VIEW'),
            'confirmation_key'=>Uuid::v4()]);
    }

    #[Route('/admin/commerce/orders/{id}/confirm-bank',name:'admin_commerce_confirm_bank',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function confirmBank(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('PLATFORM.PAYMENT.MANAGE'); $id=(int)$this->param('id');
        return $this->handle(function () use ($actor,$id): void {
            $amount=$this->parseAmount($this->posted('amount'));
            $this->payments->confirmBank($actor,$id,$this->posted('request_key'),$amount,$this->posted('received_at'),$this->posted('bank_reference'),$this->posted('reason'));
            $this->flash('success','Bank payment recorded and the order settlement checked.');
            $this->redirect('/admin/commerce/orders/'.$id);
        },'/admin/commerce/orders/'.$id);
    }

    #[Route('/admin/commerce/orders/{id}/release-review',name:'admin_commerce_release_review',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function releaseReview(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('PLATFORM.PAYMENT.RECONCILE'); $id=(int)$this->param('id');
        return $this->handle(function () use ($actor,$id): void {
            $this->payments->releasePaidReview($actor,$id,$this->posted('reason'));
            $this->flash('success','The paid order was released and fulfilled.');
            $this->redirect('/admin/commerce/orders/'.$id);
        },'/admin/commerce/orders/'.$id);
    }

    #[Route('/admin/commerce/orders/{id}/refund',name:'admin_commerce_refund',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function refund(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('PLATFORM.REFUND.MANAGE'); $id=(int)$this->param('id');
        return $this->handle(function () use ($actor,$id): void {
            $amount=$this->posted('amount')==='' ? 0 : $this->parseAmount($this->posted('amount'));
            $this->refunds->approve($actor,$id,(int)$this->posted('item_id'),$this->posted('request_key'),$this->posted('basis'),$this->posted('reason'),(int)$this->posted('quantity'),$amount);
            $this->flash('success','Refund approved, credited to Account Funds and documented with a credit note.');
            $this->redirect('/admin/commerce/orders/'.$id);
        },'/admin/commerce/orders/'.$id);
    }

    #[Route('/admin/commerce/orders/{id}/documents/{document}',name:'admin_commerce_document',requirements:['id'=>'[0-9]+','document'=>'[0-9]+'],methods:['GET'])]
    public function document(): Response
    {
        $actor=$this->requirePermission('PLATFORM.ORDER.VIEW');
        foreach ($this->records->documents((int)$this->param('id')) as $document) {
            if ((int)$document['id']!==(int)$this->param('document')) continue;
            if ($document['kind']==='receipt' && !$actor->hasPermission('PLATFORM.PAYMENT.VIEW')) throw $this->notFound('Document not found.');
            if ($document['kind']==='credit_note' && !$actor->hasPermission('PLATFORM.REFUND.VIEW')) throw $this->notFound('Document not found.');
            $disposition=$this->request()->query->get('download')==='1'?'attachment':'inline';
            return new Response($this->pdfs->render($document),200,['Content-Type'=>'application/pdf','Content-Disposition'=>$disposition.'; filename="'.$document['number'].'.pdf"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
        }
        throw $this->notFound('Document not found.');
    }

    private function parseAmount(string $value): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/',$value) || strlen($value)>16) throw new RuntimeException('Enter an amount in rands and cents, such as 123.45.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return ((int)$whole)*100+(int)str_pad($fraction,2,'0');
    }
}
