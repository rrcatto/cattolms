<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\PaymentAdministrationService;
use CattoLearning\Commerce\Application\RefundAdministrationService;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Domain\OrderTotals;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Support\AccountReference;
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
        private readonly RefundAdministrationService $refunds, private readonly FinancialDocuments $financialDocuments,
        private readonly WalletService $wallets, private readonly CommerceSettings $settings,
        private readonly \CattoLearning\Commerce\Application\MoneyRecords $money,
        private readonly \CattoLearning\Commerce\Application\OrderStory $story)
    { parent::__construct($auth,$view,$requests); }

    #[Route('/admin/commerce/orders',name:'admin_commerce_orders',methods:['GET'])]
    public function orders(): Response
    {
        $this->requirePermission('PLATFORM.ORDER.VIEW');
        return $this->render('admin-commerce-orders',['title'=>'Orders & Payments','active_nav'=>'admin']+$this->money->orders($this->request()->query->all()));
    }

    #[Route('/admin/commerce/orders/{id}',name:'admin_commerce_order',requirements:['id'=>'[0-9]+'],methods:['GET'])]
    public function order(): Response
    {
        $actor=$this->requirePermission('PLATFORM.ORDER.VIEW');
        $id=(int)$this->param('id');
        $order=$this->records->order($id);
        $snapshot=CommerceRepository::decode((string)$order['snapshot']);
        $items=$this->records->items($id);
        $canRefund=$actor->hasPermission('PLATFORM.REFUND.MANAGE') && in_array($order['state'],['paid','fulfilled','manual_review','partially_refunded'],true);
        foreach ($items as &$item) {
            $details=CommerceRepository::decode((string)$item['snapshot']);
            $item['is_bundle']=$item['product_type']==='bundle';
            $item['title']=$item['is_bundle'] ? 'Bundle: '.$details['bundle_title'] : ($details['course_title'] ?? 'Course');
            $item['includes']=$item['is_bundle'] ? implode(', ',array_map(static fn(array $c): string => (string)$c['title'],(array)$details['courses'])) : '';
            // Per course: the access source this bundle line created, and whether another source also held the course then.
            $titles=$item['is_bundle'] ? array_column((array)$details['courses'],'title','course_id') : [];
            $item['grants']=$item['is_bundle'] ? array_map(static fn(array $g): array => ['title'=>(string)($titles[(int)$g['course_id']] ?? 'Course '.(int)$g['course_id']),
                'outcome'=>$g['shared_at_grant'] ? 'granted by this bundle, alongside access from another source' : 'granted by this bundle','state'=>(string)$g['entitlement_state']],$this->records->bundleGrants((int)$item['id'])) : [];
            $item['amount_label']=Money::strictMinorUnits((int)$item['amount_minor'],(string)$order['currency'])->format();
            // What the line was paid bounds its refunds: its price less its share of the promotion discount.
            $item['paid_minor']=RefundAdministrationService::paidAmount($item);
            $item['paid_label']=Money::strictMinorUnits($item['paid_minor'],(string)$order['currency'])->format();
            $item['discount_label']=(int)$item['discount_minor']>0 ? OrderTotals::negative((int)$item['discount_minor'],(string)$order['currency']) : null;
            $item['refunded_minor']=$this->records->refundedAmount($id,(int)$item['id']);
            $item['refunded_label']=Money::strictMinorUnits((int)$item['refunded_minor'],(string)$order['currency'])->format();
            $item['remaining_label']=Money::strictMinorUnits(max(0,$item['paid_minor']-(int)$item['refunded_minor']),(string)$order['currency'])->format();
            $item['unissued']=$order['company_id']!==null && $this->records->purchasedCredit((int)$item['id'])===null;
            $item['latest_refundable']=$order['company_id']!==null ? $this->records->latestRefundableCredit((int)$order['company_id'],(int)$item['course_id'],(int)$item['access_period_seconds']) : null;
            $unit=(int)$item['amount_minor']>0 && (int)$item['quantity']>0 ? intdiv((int)$item['amount_minor'],(int)$item['quantity']) : 0;
            $item['refundable_quantity']=$item['unissued'] && $unit>0 ? (int)$item['quantity']-intdiv((int)$item['refunded_minor'],$unit) : (int)($item['latest_refundable']['available_count'] ?? 0);
            $item['refund_key']=Uuid::v4();
            // Why the Refund Policy refuses this line now; a refused refund needs ADMIN's written override reason.
            $item['rule_refusals']=$canRefund && $item['refunded_minor']<$item['paid_minor'] ? $this->refunds->ruleRefusals($order,$item) : [];
            // What a refund of everything left on the line would come to, each way it can go.
            $left=max(0,$item['paid_minor']-(int)$item['refunded_minor']);
            $item['quotes']=[];
            if ($canRefund && $left>0 && $order['company_id']===null) {
                foreach (['wallet'=>'As wallet credit','money_back'=>'As money back'] as $destination=>$label) {
                    $quote=$this->refunds->quote($order,$item,$left,$destination);
                    $item['quotes'][]=['label'=>$label,'text'=>self::quoteText($quote,(string)$order['currency'])];
                }
            }
        }
        unset($item);
        $payments=$actor->hasPermission('PLATFORM.PAYMENT.VIEW') ? $this->records->payments($id) : [];
        foreach ($payments as &$payment) $payment['evidence']=$this->records->manualEvidence((string)$payment['id']);
        unset($payment);
        $refunds=$actor->hasPermission('PLATFORM.REFUND.VIEW') ? $this->records->refunds($id) : [];
        foreach ($refunds as &$refund) {
            $refund['amount_label']=Money::strictMinorUnits((int)$refund['amount_minor'],(string)$refund['currency'])->format();
            $refund['net_label']=Money::ofMinorUnits((int)$refund['net_minor'],(string)$refund['currency'])->format();
            $refund['wallet_part_label']=Money::ofMinorUnits((int)$refund['wallet_part_minor'],(string)$refund['currency'])->format();
            $refund['deduction_lines']=array_map(static fn(array $d): string => $d['label'].': '.Money::strictMinorUnits((int)$d['amount_minor'],(string)$refund['currency'])->format(),CommerceRepository::decode((string)$refund['deductions']));
            $refund['to_pay']=$refund['destination']==='money_back' && (int)$refund['net_minor']>0 && $refund['payout_paid_at']===null;
            $refund['payout_instruction']=$refund['to_pay'] ? RefundAdministrationService::payoutInstruction($refund) : '';
        }
        unset($refund);
        $owner=WalletOwner::forOrder($order);
        $reference=(string)($order['company_id']!==null
            ? $this->records->companyAccountReference((int)$order['company_id'])
            : $this->records->userAccountReference((int)$order['purchaser_user_id']));
        $due=$this->records->dueAmount($id);
        $documents=array_values(array_filter($this->records->documents($id),fn(array $document): bool => match ($document['kind']) {
            'receipt'=>$actor->hasPermission('PLATFORM.PAYMENT.VIEW'),
            'credit_note'=>$actor->hasPermission('PLATFORM.REFUND.VIEW'),
            default=>true,
        }));
        return $this->render('admin-commerce-order',['title'=>'Order CL-'.str_pad((string)$id,8,'0',STR_PAD_LEFT),'active_nav'=>'admin',
            // The page opens with what happened, in plain English (owner, 2026-10-18).
            'story'=>$this->story->tell($id,AccountReference::format($reference)),
            'today'=>(new \DateTimeImmutable('now',new \DateTimeZone(date_default_timezone_get())))->format('Y-m-d'),
            'order'=>$order,'snapshot'=>$snapshot,'totals'=>OrderTotals::forSnapshot($snapshot),'promotion_eligible_label'=>is_array($snapshot['promotion'] ?? null) ? Money::strictMinorUnits((int)$snapshot['promotion']['eligible_minor'],(string)$order['currency'])->format() : null,'billing_lines'=>BillingDetails::fromSnapshot((array)($snapshot['billing'] ?? []))->documentLines(),'items'=>$items,'payments'=>$payments,'refunds'=>$refunds,'documents'=>$documents,
            'total_label'=>Money::strictMinorUnits((int)$order['total_minor'],(string)$order['currency'])->format(),
            'wallet_balance_label'=>$actor->hasPermission('PLATFORM.WALLET.VIEW') ? Money::ofMinorUnits($this->wallets->balance($owner,(string)$order['currency']),(string)$order['currency'])->format() : null,
            'wallet_href'=>'/admin/wallets/'.($owner->isCompany() ? 'company/'.$owner->companyId : 'person/'.$owner->userId),
            'account_reference'=>AccountReference::format($reference),'due_minor'=>$due,'due_label'=>Money::ofMinorUnits($due,(string)$order['currency'])->format(),
            'paid_label'=>Money::ofMinorUnits($this->records->paidTotal($id),(string)$order['currency'])->format(),
            'payment_method'=>$this->records->paymentMethod($id),'can_confirm'=>$actor->hasPermission('PLATFORM.PAYMENT.MANAGE') && $actor->hasPermission('PLATFORM.PAYMENT.RECONCILE'),
            'can_reconcile'=>$actor->hasPermission('PLATFORM.PAYMENT.RECONCILE'),'can_refund'=>$actor->hasPermission('PLATFORM.REFUND.MANAGE'),'refund_window'=>$this->settings->refundWindow()->words(),
            'can_view_payments'=>$actor->hasPermission('PLATFORM.PAYMENT.VIEW'),
            'confirmation_key'=>Uuid::v4()]);
    }

    #[Route('/admin/commerce/orders/{id}/confirm-bank',name:'admin_commerce_confirm_bank',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function confirmBank(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('PLATFORM.PAYMENT.MANAGE'); $id=(int)$this->param('id');
        return $this->handle(function () use ($actor,$id): void {
            $amount=EftForm::amount($this->posted('amount'));
            $this->payments->confirmBank($actor,$id,$this->posted('request_key'),$amount,EftForm::receivedAt($this->posted('received_on'),$this->posted('received_time')),$this->posted('bank_reference'),$this->posted('reason'));
            $this->flash('success','The EFT payment is recorded.');
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
            $amount=$this->posted('amount')==='' ? 0 : EftForm::amount($this->posted('amount'));
            $destination=$this->posted('destination')==='money_back' ? 'money_back' : 'wallet';
            $this->refunds->approve($actor,$id,(int)$this->posted('item_id'),$this->posted('request_key'),$this->posted('basis'),$this->posted('reason'),(int)$this->posted('quantity'),$amount,$this->posted('override_reason'),$destination);
            $this->flash('success',$destination==='money_back' ? 'Refund approved and documented with a credit note. Pay it as shown under Approved refunds, then record its reference.' : 'Refund approved, credited to the wallet and documented with a credit note.');
            $this->redirect('/admin/commerce/orders/'.$id);
        },'/admin/commerce/orders/'.$id);
    }

    #[Route('/admin/commerce/refunds/{id}/payout',name:'admin_commerce_refund_payout',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function payout(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('PLATFORM.REFUND.MANAGE'); $id=(int)$this->param('id');
        $refund=$this->records->refund($id) ?? throw $this->notFound('Refund not found.');
        $back='/admin/commerce/orders/'.(int)$refund['order_id'];
        return $this->handle(function () use ($actor,$id,$back): void {
            $this->refunds->recordPayout($actor,$id,$this->posted('paid_on'),$this->posted('reference'));
            $this->flash('success','The refund payment and its reference are recorded.');
            $this->redirect($back);
        },$back);
    }

    /**
     * One line of a refund quote: the wallet part, the rest less the deductions, and the total.
     *
     * @param array{gross:int,wallet_part:int,external:int,method:string,deductions:list<array{kind:string,label:string,amount_minor:int}>,net:int} $quote
     */
    private static function quoteText(array $quote, string $currency): string
    {
        $parts=[];
        if ($quote['wallet_part']>0) $parts[]=Money::strictMinorUnits($quote['wallet_part'],$currency)->format().' paid from the wallet goes back to the wallet';
        if ($quote['external']>0) {
            $text=Money::strictMinorUnits($quote['external'],$currency)->format().' paid by '.($quote['method']==='eft' ? 'EFT' : 'card');
            foreach ($quote['deductions'] as $deduction) $text.=', less '.$deduction['label'].' '.Money::strictMinorUnits($deduction['amount_minor'],$currency)->format();
            $parts[]=$text.' = '.Money::ofMinorUnits($quote['net'],$currency)->format();
        }
        return implode('; ',$parts).'.';
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
            return new Response($this->financialDocuments->pdf($document),200,['Content-Type'=>'application/pdf','Content-Disposition'=>$disposition.'; filename="'.$document['number'].'.pdf"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
        }
        throw $this->notFound('Document not found.');
    }
}
