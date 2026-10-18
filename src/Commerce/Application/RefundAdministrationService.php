<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Domain\RefundRule;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\LedgerKind;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Explicit ADMIN refund decisions (owner, 2026-10-17).
 *
 * A line can return at most what was paid for it: its price less its share of the order's promotion
 * discount. Of that, the share the order paid from the wallet (the line's wallet_minor) always goes back
 * to the wallet credit it came from, keeping that credit's expiry. The rest was paid by card (Stitch) or
 * EFT and goes where the purchaser chose: wallet credit, or money back paid outside the LMS (a company
 * is always refunded as wallet credit). The owner's deductions apply to that rest:
 * - card, money back: the line's share of the Stitch transaction fee and Stitch's refund fee;
 * - card, wallet credit: the line's share of the Stitch transaction fee;
 * - EFT, money back: the EFT refund fee for the amount;
 * - EFT, wallet credit: nothing.
 * Each part is its own ledger row, and the refund records the wallet part, the deductions and the net.
 * A money-back refund is due within 7 days of the request; ADMIN records its reference when it is paid.
 *
 * Refunding all that was paid for a line revokes the entitlement sources that line created and
 * nothing else. A bundle line is refunded as the product that was bought, never course by course.
 *
 * The Refund Policy's rule (RefundRule) is enforced here: a started course is not refunded, and every
 * course starts on payment; an unstarted course and company credits only within the refund window.
 * A refund asked for in the LMS is judged by the rule as it stood when it was asked. ADMIN may approve a
 * refused refund with a written override reason, recorded with the refund and shown on the ADMIN order
 * page only.
 */
final class RefundAdministrationService
{
    /** A money-back refund is paid within this many days of the request (Refund Policy). */
    public const PAYOUT_DAYS = 7;

    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions,
        private readonly TransitionService $transitions, private readonly ClockInterface $clock, private readonly AnalyticsEventRecorder $analytics,
        private readonly AccessService $access, private readonly FinancialDocuments $documents, private readonly WalletService $wallets,
        private readonly CommerceSettings $settings) {}

    /**
     * Why the Refund Policy refuses a refund of this order line now; empty when the rule allows it.
     * The purchase is the order's confirmed payment (its placement for an order with none).
     *
     * @param array<string,mixed> $order a commerce_orders row
     * @param array<string,mixed> $item one of its commerce_order_items rows
     * @return list<string>
     *
     * @phpstan-impure the answer changes as the learner starts and as time passes
     */
    public function ruleRefusals(array $order, array $item): array
    {
        $purchasedAt=new DateTimeImmutable($this->records->paidAt((int)$order['id']) ?? (string)$order['placed_at']);
        $company=$order['company_id']!==null;
        $started=[];
        if (!$company) {
            $snapshot=CommerceRepository::decode((string)$item['snapshot']);
            $titles=array_column((array)($snapshot['courses'] ?? []),'title','course_id');
            foreach ($this->records->startedCourses((int)$item['id']) as $course) {
                $started[]=['title'=>(string)($titles[$course['course_id']] ?? $snapshot['course_title'] ?? 'a course'),'started_at'=>$course['started_at']];
            }
        }
        return RefundRule::refusals($purchasedAt,$this->clock->now(),$this->settings->refundWindow(),$started,$item['product_type']==='bundle',$company);
    }

    /**
     * Works out a refund without recording it: the wallet part, the rest and how it was paid, the
     * deductions and the net, for the refund form and for approve().
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     * @return array{gross:int,wallet_part:int,external:int,method:string,deductions:list<array{kind:string,label:string,amount_minor:int}>,net:int}
     */
    public function quote(array $order, array $item, int $grossMinor, string $destination): array
    {
        $paid=self::paidAmount($item);
        if ($order['company_id']!==null) $destination='wallet';
        $snapshot=CommerceRepository::decode((string)$item['snapshot']);
        $walletLine=(int)($snapshot['wallet_minor'] ?? 0);
        $refunded=$this->records->refundedAmount((int)$order['id'],(int)$item['id']);
        $walletRefunded=$this->records->refundedWalletPart((int)$order['id'],(int)$item['id']);
        $walletLeft=max(0,$walletLine-$walletRefunded);
        $externalLeft=max(0,($paid-$walletLine)-($refunded-$walletRefunded));
        $gross=min($grossMinor,$walletLeft+$externalLeft);
        $walletPart=$walletLeft+$externalLeft===0 ? 0 : min($walletLeft,(int)round($gross*$walletLeft/($walletLeft+$externalLeft)));
        $external=$gross-$walletPart;
        $method=$this->externalMethod((int)$order['id']);
        $deductions=[];
        if ($external>0 && $method==='stitch') {
            $fee=$this->transactionFeeShare((int)$order['id'],$external);
            if ($fee>0) $deductions[]=['kind'=>'stitch_transaction_fee','label'=>'Stitch transaction fee on this payment','amount_minor'=>$fee];
            if ($destination==='money_back') {
                $refundFee=$this->settings->stitchRefundFee()->on($external);
                if ($refundFee>0) $deductions[]=['kind'=>'stitch_refund_fee','label'=>'Stitch refund fee','amount_minor'=>$refundFee];
            }
        }
        if ($external>0 && $method==='eft' && $destination==='money_back') {
            $fee=$this->settings->eftRefundFee()->feeFor($external);
            if ($fee>0) $deductions[]=['kind'=>'eft_refund_fee','label'=>'EFT refund fee','amount_minor'=>$fee];
        }
        // Deductions never take more than the refunded money: each takes what the ones before it left.
        $taken=0; $kept=[];
        foreach ($deductions as $deduction) {
            $amount=min($deduction['amount_minor'],$external-$taken);
            if ($amount<=0) continue;
            $deduction['amount_minor']=$amount;
            $taken+=$amount;
            $kept[]=$deduction;
        }
        $deductions=$kept;
        return ['gross'=>$gross,'wallet_part'=>$walletPart,'external'=>$external,'method'=>$external>0?$method:'none','deductions'=>$deductions,'net'=>$external-$taken];
    }

    /**
     * $overrideReason is ADMIN's written reason for approving a refund the Refund Policy refuses. It
     * is required then, and ignored (nothing is recorded as an override) when the rule allows the refund.
     * $destination is wallet or money_back; a company refund is always wallet credit. $requestId names
     * the refund request being approved, whose rule verdict at request time is the one applied.
     */
    public function approve(CurrentUser $actor, int $orderId, int $itemId, string $requestKey, string $basis, string $reason, int $quantity, int $amountMinor, string $overrideReason = '', string $destination = 'wallet', ?int $requestId = null): int
    {
        OrderService::requireCapability($actor,'PLATFORM.REFUND.MANAGE');
        if (!Uuid::isValid($requestKey)) throw new RuntimeException('Invalid refund identifier.');
        if (!in_array($basis,['statutory','service_failure','goodwill','voluntary'],true)) throw new RuntimeException('Select a refund basis.');
        if (!in_array($destination,['wallet','money_back'],true)) throw new RuntimeException('Choose where the refund goes: wallet credit or money back.');
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>2000) throw new RuntimeException('Record the refund decision and its reason.');
        $overrideReason=trim($overrideReason);
        return $this->transactions->run(function () use ($actor,$orderId,$itemId,$requestKey,$basis,$reason,$quantity,$amountMinor,$overrideReason,$destination,$requestId): int {
            $order=$this->records->order($orderId);
            $this->records->lockPurchaser((int)$order['purchaser_user_id']);
            $order=$this->records->order($orderId,true);
            $existing=$this->records->refundByKey($requestKey);
            if ($existing!==null) {
                if ((int)$existing['order_id']!==$orderId || (int)$existing['order_item_id']!==$itemId) throw new RuntimeException('This refund identifier belongs to another order item.');
                return (int)$existing['id'];
            }
            if (!in_array($order['state'],['fulfilled','paid','manual_review','partially_refunded'],true)) throw new RuntimeException('Only a settled order can be refunded.');
            if ($this->records->paidTotal($orderId)<(int)$order['total_minor']) throw new RuntimeException('The order has not been fully paid.');
            $item=$this->records->orderItem($itemId,$orderId);
            $now=$this->clock->now();
            $request=$requestId===null ? null : $this->records->refundRequest($requestId);
            if ($request!==null && ((int)$request['order_item_id']!==$itemId || $request['status']!=='requested')) throw new RuntimeException('This refund request is not open for this order line.');
            $refusals=$request!==null ? ((string)$request['rule_refusals']==='' ? [] : [(string)$request['rule_refusals']]) : $this->ruleRefusals($order,$item);
            if ($refusals!==[]) {
                if ($overrideReason==='') throw new RuntimeException('The Refund Policy does not allow this refund. '.implode(' ',$refusals).' To approve it anyway, record an override reason.');
                if (mb_strlen($overrideReason)<5 || mb_strlen($overrideReason)>2000) throw new RuntimeException('Record why the Refund Policy is overridden, in 5 to 2,000 characters.');
            }
            $policyRefusal=$refusals===[] ? null : implode(' ',$refusals);
            $company=$order['company_id']!==null;
            if ($company) {
                $destination='wallet';
                if ($quantity<1 || $amountMinor!==0) throw new RuntimeException('Enter an unused credit quantity; its refund value is calculated from the historical order.');
                $unit=intdiv((int)$item['amount_minor'],(int)$item['quantity']);
                if ($unit*(int)$item['quantity']!==(int)$item['amount_minor']) throw new RuntimeException('Historical credit price is not divisible by quantity.');
                if ($this->records->purchasedCredit($itemId)===null) {
                    $remaining=(int)$item['amount_minor']-$this->records->refundedAmount($orderId,$itemId);
                    if ($quantity*$unit>$remaining) throw new RuntimeException('The refund exceeds this unissued company credit line.');
                } else {
                    $lot=$this->records->latestRefundableCredit((int)$order['company_id'],(int)$item['course_id'],(int)$item['access_period_seconds'],true);
                    if ($lot===null || (int)$lot['commerce_order_item_id']!==$itemId) throw new RuntimeException('Refund the newest eligible purchased credit lot first.');
                    $unused=(int)$lot['quantity']-(int)$lot['refunded_quantity']-(int)$this->records->creditAllocationCount((int)$lot['id']);
                    if ($quantity>$unused) throw new RuntimeException('The requested quantity exceeds unallocated credit units.');
                    $this->records->refundCreditUnits((int)$lot['id'],$quantity);
                }
                $amountMinor=$unit*$quantity;
            } else {
                if ($quantity!==1 || $amountMinor<1) throw new RuntimeException('Enter a positive individual refund amount.');
                $remaining=self::paidAmount($item)-$this->records->refundedAmount($orderId,$itemId);
                if ($amountMinor>$remaining) throw new RuntimeException('The refund exceeds this item’s unrefunded paid amount.');
            }
            $quote=$this->quote($order,$item,$amountMinor,$destination);
            $requestedAt=$request!==null ? new DateTimeImmutable((string)$request['requested_at']) : $now;
            $id=$this->records->createRefund($requestKey,$orderId,$itemId,$quantity,$amountMinor,(string)$order['currency'],$basis,$reason,$actor->id,$now->format(DATE_ATOM),$policyRefusal,$policyRefusal===null ? null : $overrideReason,
                ['request_id'=>$requestId,'destination'=>$destination,'method'=>$quote['method'],'wallet_part_minor'=>$quote['wallet_part'],'deductions'=>$quote['deductions'],'net_minor'=>$quote['net'],
                    'payout_due_at'=>$destination==='money_back' && $quote['net']>0 ? $requestedAt->modify('+'.self::PAYOUT_DAYS.' days')->format(DATE_ATOM) : null]);
            $this->recordLedger($order,$item,$id,$quote,$destination);
            $itemSnapshot=CommerceRepository::decode((string)$item['snapshot']);
            $snapshot=CommerceRepository::decode((string)$order['snapshot']);
            $bundle=$item['product_type']==='bundle';
            $snapshot['items']=[$bundle
                ? ['fulfilment_type'=>'bundle','bundle_title'=>$itemSnapshot['bundle_title'],'courses'=>$itemSnapshot['courses'],'access_period_seconds'=>$item['access_period_seconds'],'quantity'=>$quantity,'line_total_minor'=>$amountMinor,'currency'=>$order['currency']]
                : ['course_title'=>$itemSnapshot['course_title'],'access_period_seconds'=>$item['access_period_seconds'],'quantity'=>$quantity,'line_total_minor'=>$amountMinor,'currency'=>$order['currency']]];
            // The credit note states what the refunded line was paid; the order's totals are not its own.
            $snapshot['refund_line']=['price_minor'=>(int)$item['amount_minor'],'discount_minor'=>(int)$item['discount_minor'],'paid_minor'=>self::paidAmount($item),'quantity'=>(int)$item['quantity'],'promotion_code'=>$snapshot['promotion']['code'] ?? null];
            unset($snapshot['promotion'],$snapshot['subtotal_minor'],$snapshot['discount_minor']);
            $snapshot['total_minor']=$amountMinor;
            $snapshot['refund_id']=$id;
            $snapshot['refund_basis']=$basis;
            $snapshot['refund_reason']=$reason;
            $snapshot['refund_settlement']=['destination'=>$destination,'wallet_part_minor'=>$quote['wallet_part'],'deductions'=>$quote['deductions'],'net_minor'=>$quote['net']];
            $this->documents->issueCreditNote($orderId,$id,$snapshot,$now->format(DATE_ATOM),$actor->id);
            $fullyRefunded=$this->records->refundedAmount($orderId,$itemId)>=self::paidAmount($item);
            $access=['revoked'=>0,'kept'=>0];
            if (!$company && $fullyRefunded) {
                $sources=$this->records->sourcesForItem($itemId);
                $open=$this->access->revoke($sources,'refund:'.$id);
                $access=['revoked'=>count($sources),'kept'=>count(array_filter($open))];
            }
            if ($request!==null) $this->records->decideRefundRequest((int)$request['id'],'approved',$actor->id,$now->format(DATE_ATOM),$reason);
            if ($bundle) $this->recordBundleRefund($order,$item,$id,$amountMinor,$fullyRefunded,$access);
            else $this->recordRefund($order,$item,$id,$quantity,$amountMinor,$fullyRefunded);
            $total=$this->records->refundedAmount($orderId);
            $transition=$total>=(int)$order['total_minor']?'mark_refunded':'mark_partially_refunded';
            $this->records->setOrderState($orderId,$this->transitions->apply('order',(string)$order['state'],$transition));
            $this->records->audit($orderId,$actor->id,'refund.approved',['refund_id'=>$id,'order_item_id'=>$itemId,'quantity'=>$quantity,'amount_minor'=>$amountMinor,'basis'=>$basis,'reason'=>$reason,'destination'=>$destination,'net_minor'=>$quote['net']]+($policyRefusal===null?[]:['policy_refusal'=>$policyRefusal,'override_reason'=>$overrideReason])+($bundle?['bundle_access'=>$access]:[]),$now->format(DATE_ATOM));
            return $id;
        });
    }

    /**
     * Records that a money-back refund was paid outside the LMS, through the Stitch dashboard or by
     * EFT, with its reference: the ledger's refund_paid row (and Stitch's charge to the business for a
     * card refund).
     */
    public function recordPayout(CurrentUser $actor, int $refundId, string $paidAt, string $reference): void
    {
        OrderService::requireCapability($actor,'PLATFORM.REFUND.MANAGE');
        $reference=trim($reference);
        if (mb_strlen($reference)<3 || mb_strlen($reference)>200) throw new RuntimeException('Enter the Stitch or bank reference of the refund payment.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$paidAt)) throw new RuntimeException('Enter the date the refund was paid.');
        $paid=new DateTimeImmutable($paidAt.' 12:00:00');
        if ($paid>$this->clock->now()->modify('+1 day')) throw new RuntimeException('The payment date cannot be in the future.');
        $this->transactions->run(function () use ($actor,$refundId,$paid,$reference): void {
            $refund=$this->records->refund($refundId) ?? throw new RuntimeException('Refund not found.');
            $order=$this->records->order((int)$refund['order_id']);
            $this->records->lockPurchaser((int)$order['purchaser_user_id']);
            $refund=$this->records->refund($refundId) ?? throw new RuntimeException('Refund not found.');
            if ($refund['destination']!=='money_back' || (int)$refund['net_minor']<=0) throw new RuntimeException('This refund went to the wallet; there is nothing to pay out.');
            if ($refund['paid_at']!==null) throw new RuntimeException('This refund is already recorded as paid.');
            $method=(string)$refund['method']==='stitch' ? 'stitch' : 'eft';
            $now=$this->clock->now()->format(DATE_ATOM);
            $this->records->recordRefundPayout($refundId,$method,(int)$refund['net_minor'],$paid->format(DATE_ATOM),$reference,$actor->id,$now);
            $owner=WalletOwner::forOrder($order);
            $refs=['order_id'=>(int)$order['id'],'order_item_id'=>(int)$refund['order_item_id'],'refund_id'=>$refundId,'external_reference'=>$reference,'actor_user_id'=>$actor->id];
            $this->wallets->record('refund_paid',$owner,(int)$refund['net_minor'],(string)$refund['currency'],$method,$refs,$paid->format(DATE_ATOM));
            foreach (CommerceRepository::decode((string)$refund['deductions']) as $deduction) {
                if (($deduction['kind'] ?? '')==='stitch_refund_fee') $this->wallets->record('processor_refund_fee',null,(int)$deduction['amount_minor'],(string)$refund['currency'],'stitch',$refs+['memo'=>'Stitch refund fee'],$paid->format(DATE_ATOM));
            }
            $this->records->audit((int)$order['id'],$actor->id,'refund.paid_out',['refund_id'=>$refundId,'method'=>$method,'reference'=>$reference],$now);
        });
    }

    /**
     * How to pay a money-back refund, for ADMIN: through the Stitch dashboard for a card payment, or
     * by EFT for an EFT payment.
     *
     * @param array<string,mixed> $refund a commerce_refunds row
     */
    public static function payoutInstruction(array $refund): string
    {
        $amount=Money::strictMinorUnits((int)$refund['net_minor'],(string)$refund['currency'])->format();
        return (string)$refund['method']==='stitch'
            ? 'Refund '.$amount.' through the Stitch dashboard to the card that paid for this order, then record the Stitch refund reference here.'
            : 'Pay '.$amount.' by EFT to the bank account the purchaser gives you, then record the bank reference here.';
    }

    /**
     * The ledger rows for one refund: the wallet part back to the credit it came from, the rest as new
     * wallet credit or as money owed, and each deduction.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     * @param array{gross:int,wallet_part:int,external:int,method:string,deductions:list<array{kind:string,label:string,amount_minor:int}>,net:int} $quote
     */
    private function recordLedger(array $order, array $item, int $refundId, array $quote, string $destination): void
    {
        $owner=WalletOwner::forOrder($order);
        $currency=(string)$order['currency'];
        $refs=['order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id'],'refund_id'=>$refundId];
        if ($quote['wallet_part']>0) $this->wallets->returnOrderPayment($owner,(int)$order['id'],$currency,'refund_to_wallet',$quote['wallet_part'],$refs);
        if ($quote['net']>0) {
            if ($destination==='wallet') $this->wallets->credit($owner,'refund_to_wallet',$quote['net'],$currency,$quote['method'],$refs);
            else $this->wallets->record('refund_owed',$owner,$quote['net'],$currency,$quote['method'],$refs);
        }
        foreach ($quote['deductions'] as $deduction) {
            $this->wallets->record('refund_deduction',$owner,$deduction['amount_minor'],$currency,$quote['method'],$refs+['memo'=>$deduction['label']]);
        }
    }

    /** stitch when the order's money (beyond the wallet) came by card, eft when it came by EFT. */
    private function externalMethod(int $orderId): string
    {
        $method='none';
        foreach ($this->records->payments($orderId) as $payment) {
            if ($payment['gateway']==='wallet' || !in_array($payment['state'],['paid','partially_refunded','refunded'],true)) continue;
            $method=LedgerKind::methodForGateway((string)$payment['gateway']);
        }
        return $method;
    }

    /** The share of the order's recorded Stitch fees that belongs to $external of its card payments. */
    private function transactionFeeShare(int $orderId, int $external): int
    {
        $fees=0; $paid=0;
        foreach ($this->records->payments($orderId) as $payment) {
            if ($payment['gateway']==='wallet' || !in_array($payment['state'],['paid','partially_refunded','refunded'],true)) continue;
            $paid+=(int)$payment['amount_minor'];
            $fees+=$this->wallets->processorFee((string)$payment['id']);
        }
        return $paid<=0 ? 0 : (int)round($fees*$external/$paid);
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     * @param array{revoked:int,kept:int} $access
     */
    private function recordBundleRefund(array $order, array $item, int $refundId, int $amountMinor, bool $fullyRefunded, array $access): void
    {
        $this->analytics->recordSafely(AnalyticsEventType::BundleRefunded, AnalyticsSource::Admin,
            ['user_id'=>(int)$order['purchaser_user_id'],'order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id']],
            ['bundle_id'=>(int)$item['bundle_id'],'refund_id'=>$refundId,'amount_minor'=>$amountMinor,'currency'=>(string)$order['currency'],'full_refund'=>$fullyRefunded,'courses_revoked'=>$access['revoked'],'courses_kept'=>$access['kept']],
            'bundle_refunded:refund:'.$refundId);
    }

    /**
     * What an order line was paid: its price less its share of the promotion discount.
     *
     * @param array<string,mixed> $item a commerce_order_items row
     */
    public static function paidAmount(array $item): int
    {
        return (int)$item['amount_minor']-(int)$item['discount_minor'];
    }

    /**
     * One approved refund of one order line, recorded once with the refund; a replayed refund
     * request returns before reaching here, and the refund id keys the event besides. Ids and the
     * refunded figures only, never payment or bank details.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     */
    private function recordRefund(array $order, array $item, int $refundId, int $quantity, int $amountMinor, bool $fullyRefunded): void
    {
        $company=$order['company_id']!==null;
        try {
            $this->analytics->record(AnalyticsEventType::CourseRefunded, AnalyticsSource::Admin,
                ['user_id'=>(int)$order['purchaser_user_id'],'course_id'=>(int)$item['course_id'],'order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id']],
                ['refund_id'=>$refundId,'quantity'=>$quantity,'amount_minor'=>$amountMinor,'currency'=>(string)$order['currency'],'full_refund'=>$fullyRefunded,'purchaser'=>$company?'company':'individual','company_id'=>$company?(int)$order['company_id']:null],
                'course_refunded:refund:'.$refundId);
        } catch (\InvalidArgumentException $exception) {
            error_log('Refund analytics for refund '.$refundId.' was not recorded: '.$exception->getMessage());
        }
    }
}
