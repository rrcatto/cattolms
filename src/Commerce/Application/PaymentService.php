<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Contract\PaymentGatewayInterface;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Domain\PaymentRequest;
use CattoLearning\Commerce\Domain\PaymentResult;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\LedgerKind;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use Symfony\Component\Clock\ClockInterface;
use RuntimeException;

/**
 * Commits attempts before provider calls and settles verified results exactly once under order locks.
 *
 * An order can be paid in parts (owner, 2026-10-17): from the wallet when it is placed, then by card or
 * EFT for what is due. It is paid, and fulfilled, once its payments cover the total. Every payment
 * received is a ledger row; a card payment also records the processor's fee on it (the Stitch
 * transaction fee setting; the development card gateway stands in for Stitch). An EFT above what is
 * due pays the order and credits the excess to the payer's wallet; one below leaves the order waiting
 * for the rest.
 */
final class PaymentService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions, private readonly OrderService $orders, private readonly PaymentGatewayInterface $gateway, private readonly FulfilmentService $fulfilment, private readonly TransitionService $transitions, private readonly ClockInterface $clock, private readonly FinancialDocuments $documents, private readonly WalletService $wallets, private readonly CommerceSettings $settings) {}

    public function purchase(CurrentUser $actor, int $orderId, string $key, string $methodToken): string
    {
        OrderService::requireCapability($actor,'COMMERCE.CHECKOUT.START');
        if (!Uuid::isValid($key)) throw new RuntimeException('Invalid payment request identifier.');
        $this->orders->view($actor,$orderId);
        $this->orders->cancelDue($orderId);
        [$attempt,$send]=$this->transactions->run(function() use($actor,$orderId,$key): array {
            $this->records->lockPurchaser($actor->id);
            $order=$this->records->order($orderId,true);
            $existing=$this->records->paymentForRequest($orderId,$key);
            if ($existing!==null) return [$existing,false];
            if ($order['state']!=='awaiting_payment') throw new RuntimeException('This order is not awaiting payment.');
            foreach($this->records->payments($orderId) as $p) {
                if (in_array($p['state'],['created','pending','paid'],true)) throw new RuntimeException('A payment is already being processed.');
            }
            $this->records->selectPaymentMethod($orderId, 'dummy');
            $due=$this->records->dueAmount($orderId);
            if ($due<=0) throw new RuntimeException('Nothing is left to pay on this order.');
            $id=$this->records->createPayment($order,$key,$this->gateway->id(),$this->clock->now()->format(DATE_ATOM),$due);
            $state=$this->transitions->apply('payment','created','submit');
            $this->records->setPaymentState($id,$state,null);
            $this->records->paymentEvent($id,'attempt:'.$id,'pending',[],$this->clock->now()->format(DATE_ATOM));
            return [$this->records->payment($id),true];
        });
        if ($send) {
            $request=new PaymentRequest((string)$attempt['id'],Money::strictMinorUnits((int)$attempt['amount_minor'],(string)$attempt['currency']),$methodToken);
            $this->confirm($this->gateway->id(),$this->gateway->purchase($request));
        }
        return (string)$attempt['id'];
    }

    /**
     * An order a promotion discounted to zero has nothing to collect: it is settled as paid without a
     * payment attempt (a payment of nothing cannot exist) and fulfilled like any paid order. No
     * receipt is issued because nothing was received; the invoice shows the zero total.
     */
    public function settleWithoutPayment(CurrentUser $actor, int $orderId): void
    {
        OrderService::requireCapability($actor,'COMMERCE.CHECKOUT.START');
        $this->transactions->run(function() use($actor,$orderId): void {
            $this->records->lockPurchaser($actor->id);
            $order=$this->records->order($orderId,true);
            // The buyer, or any Company Administrator of the order's company (2026-10-26).
            if ((int)$order['purchaser_user_id']!==$actor->id && !(($order['company_id'] ?? null)!==null && $this->records->isCompanyAdministrator((int)$order['company_id'],$actor->id))) throw new RuntimeException('This order belongs to another purchaser.');
            if ($order['state']!=='awaiting_payment' || (int)$order['total_minor']!==0) throw new RuntimeException('This order has an amount to pay.');
            $order['state']=$this->transitions->apply('order','awaiting_payment','confirm_paid');
            $this->records->setOrderState((int)$order['id'],(string)$order['state']);
            $this->records->audit((int)$order['id'],$actor->id,'order.settled_without_payment',['promotion_id'=>$order['promotion_id']===null?null:(int)$order['promotion_id'],'discount_minor'=>(int)$order['discount_minor']],$this->clock->now()->format(DATE_ATOM));
            if (!$this->fulfilment->fulfil($order)) $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','hold_for_review'));
        });
    }

    /**
     * An order the wallet paid in full when it was placed: paid and fulfilled now, with no other
     * payment. Placement already took the credit and issued its receipt.
     */
    public function settlePaidByWallet(CurrentUser $actor, int $orderId): void
    {
        OrderService::requireCapability($actor,'COMMERCE.CHECKOUT.START');
        $this->transactions->run(function() use($actor,$orderId): void {
            $this->records->lockPurchaser($actor->id);
            $order=$this->records->order($orderId,true);
            // The buyer, or any Company Administrator of the order's company (2026-10-26).
            if ((int)$order['purchaser_user_id']!==$actor->id && !(($order['company_id'] ?? null)!==null && $this->records->isCompanyAdministrator((int)$order['company_id'],$actor->id))) throw new RuntimeException('This order belongs to another purchaser.');
            if ($order['state']!=='awaiting_payment' || $this->records->paidTotal($orderId)<(int)$order['total_minor']) throw new RuntimeException('This order has an amount to pay.');
            $order['state']=$this->transitions->apply('order','awaiting_payment','confirm_paid');
            $this->records->setOrderState($orderId,(string)$order['state']);
            if (!$this->fulfilment->fulfil($order)) $this->records->setOrderState($orderId,$this->transitions->apply('order','paid','hold_for_review'));
        });
    }

    /** Only gateway adapters/reconciliation may provide evidence; no browser route accepts a result DTO. */
    public function confirm(string $gateway, PaymentResult $result): void
    {
        $this->transactions->run(function() use($gateway,$result): void {
            $attempt=$this->records->payment($result->transactionId);
            $order=$this->records->order((int)$attempt['order_id']);
            $this->records->lockPurchaser((int)$order['purchaser_user_id']);
            $order=$this->records->order((int)$order['id'],true);
            $attempt=$this->records->payment($result->transactionId);
            if ($gateway!==$attempt['gateway'] || $result->amount->currency!==$attempt['currency'] || $result->amount->minorUnits!==(int)$attempt['amount_minor']) throw new RuntimeException('Payment evidence does not match the expected amount, currency or gateway.');
            if ($attempt['state']==='paid') {
                if ($result->state!=='paid' || $attempt['provider_reference']!==$result->providerReference) throw new RuntimeException('Conflicting evidence requires reconciliation.');
                return;
            }
            if (!in_array($attempt['state'],['created','pending'],true)) throw new RuntimeException('This payment attempt is already final.');
            if ($result->state==='paid' && !$result->providerReference) throw new RuntimeException('A successful payment needs a provider reference.');
            $key=$result->eventId!==null ? $gateway.':event:'.$result->eventId : 'result:'.$result->transactionId.':'.$result->state;
            if (!$this->records->paymentEvent($result->transactionId,$key,$result->state,['reference'=>$result->providerReference,'amount_minor'=>$result->amount->minorUnits,'currency'=>$result->amount->currency,'message'=>$result->message],$this->clock->now()->format(DATE_ATOM))) return;
            $state=$result->state==='pending'?'pending':$this->transitions->apply('payment',(string)$attempt['state'],$result->state==='paid'?'confirm':'fail');
            $this->records->setPaymentState($result->transactionId,$state,$result->providerReference);
            if ($state!=='paid') return;
            $snapshot=CommerceRepository::decode((string)$order['snapshot']);
            $this->documents->issueReceipt((int)$order['id'],$snapshot,$this->records->payment($result->transactionId),$gateway,$result->providerReference,$this->clock->now()->format(DATE_ATOM));
            $this->records->audit((int)$order['id'],null,'payment.confirmed',['payment_id'=>$result->transactionId],$this->clock->now()->format(DATE_ATOM));
            $this->recordReceived($order,$this->records->payment($result->transactionId),$gateway);
            if ($order['state']==='cancelled') {
                // Money that arrives after the order was cancelled becomes the payer's wallet credit, as
                // what was paid before the cancellation did (owner, 2026-10-17); the order stays cancelled.
                $this->wallets->credit(WalletOwner::forOrder($order),'unfulfilled_payment_to_wallet',(int)$attempt['amount_minor'],(string)$order['currency'],LedgerKind::methodForGateway($gateway),['order_id'=>(int)$order['id'],'payment_id'=>$result->transactionId]);
                $this->records->audit((int)$order['id'],null,'payment.after_cancellation',['payment_id'=>$result->transactionId,'amount_minor'=>(int)$attempt['amount_minor']],$this->clock->now()->format(DATE_ATOM));
                return;
            }
            if ($order['state']!=='awaiting_payment' || new \DateTimeImmutable((string)$order['payment_due_at'])<=$this->clock->now()) {
                if (in_array($order['state'],['awaiting_payment','cancelled','paid'],true)) $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order',(string)$order['state'],'hold_for_review'));
                return;
            }
            $paid=$this->records->paidTotal((int)$order['id']);
            if ($paid<(int)$order['total_minor']) {
                // Part of what is due: the order waits for the rest (Transactions that need attention).
                $this->records->audit((int)$order['id'],null,'payment.partial',['payment_id'=>$result->transactionId,'paid_minor'=>$paid,'total_minor'=>(int)$order['total_minor']],$this->clock->now()->format(DATE_ATOM));
                // The buyer is told what arrived and what is still to pay, with its receipt (owner, 2026-10-19).
                $this->records->enqueue('part-paid:'.$result->transactionId,'order.part_paid',['order_id'=>(int)$order['id'],'payment_id'=>$result->transactionId],$this->clock->now()->format(DATE_ATOM));
                return;
            }
            if ($paid>(int)$order['total_minor']) {
                $this->wallets->credit(WalletOwner::forOrder($order),'overpayment_to_wallet',$paid-(int)$order['total_minor'],(string)$order['currency'],LedgerKind::methodForGateway($gateway),['order_id'=>(int)$order['id'],'payment_id'=>$result->transactionId]);
                $this->records->audit((int)$order['id'],null,'payment.overpaid',['payment_id'=>$result->transactionId,'excess_minor'=>$paid-(int)$order['total_minor']],$this->clock->now()->format(DATE_ATOM));
            }
            $order['state']=$this->transitions->apply('order',(string)$order['state'],'confirm_paid');
            $this->records->setOrderState((int)$order['id'],(string)$order['state']);
            if (!$this->fulfilment->fulfil($order)) $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','hold_for_review'));
        });
    }

    /**
     * The money ledger's rows for a payment received by card or EFT: the payment, and for a card the
     * processor's fee on it. Once per payment, however often its evidence is replayed.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payment
     */
    private function recordReceived(array $order, array $payment, string $gateway): void
    {
        if ($gateway==='wallet' || $this->wallets->hasPaymentEntry((string)$payment['id'])) return;
        $method=LedgerKind::methodForGateway($gateway);
        $owner=WalletOwner::forOrder($order);
        $refs=['order_id'=>(int)$order['id'],'payment_id'=>(string)$payment['id'],'external_reference'=>$payment['provider_reference'] ?? null];
        $this->wallets->record('payment_received',$owner,(int)$payment['amount_minor'],(string)$payment['currency'],$method,$refs);
        if ($method==='stitch') {
            $this->wallets->record('processor_fee',$owner,$this->settings->stitchFee()->on((int)$payment['amount_minor']),(string)$payment['currency'],'stitch',$refs+['memo'=>'Stitch transaction fee '.$this->settings->stitchFee()->percent.'% plus '.Money::strictMinorUnits($this->settings->stitchFee()->fixedMinor,'ZAR')->format()]);
        }
    }
}
