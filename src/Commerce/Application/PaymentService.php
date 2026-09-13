<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Contract\PaymentGatewayInterface;
use CattoLearning\Commerce\Domain\PaymentRequest;
use CattoLearning\Commerce\Domain\PaymentResult;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use Symfony\Component\Clock\ClockInterface;
use RuntimeException;

/** Commits attempts before provider calls and settles verified results exactly once under order locks. */
final class PaymentService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions, private readonly OrderService $orders, private readonly PaymentGatewayInterface $gateway, private readonly FulfilmentService $fulfilment, private readonly TransitionService $transitions, private readonly ClockInterface $clock) {}

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
            $id=$this->records->createPayment($order,$key,$this->gateway->id(),$this->clock->now()->format(DATE_ATOM));
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
            $this->records->document((int)$order['id'],'receipt','payment:'.$result->transactionId,$snapshot+['payment_id'=>$result->transactionId,'gateway'=>$gateway,'provider_reference'=>$result->providerReference],$this->clock->now()->format(DATE_ATOM));
            $this->records->audit((int)$order['id'],null,'payment.confirmed',['payment_id'=>$result->transactionId],$this->clock->now()->format(DATE_ATOM));
            if ($order['state']!=='awaiting_payment' || new \DateTimeImmutable((string)$order['payment_due_at'])<=$this->clock->now()) {
                if (in_array($order['state'],['awaiting_payment','cancelled','paid'],true)) $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order',(string)$order['state'],'hold_for_review'));
                return;
            }
            $order['state']=$this->transitions->apply('order',(string)$order['state'],'confirm_paid');
            $this->records->setOrderState((int)$order['id'],(string)$order['state']);
            if (!$this->fulfilment->fulfil($order)) $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','hold_for_review'));
        });
    }
}
