<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Domain\PaymentResult;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/** ADMIN-only bank evidence and explicit release of fully paid manual-review orders. */
final class PaymentAdministrationService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions,
        private readonly PaymentService $payments, private readonly FulfilmentService $fulfilment,
        private readonly TransitionService $transitions, private readonly ClockInterface $clock) {}

    public function confirmBank(CurrentUser $actor, int $orderId, string $requestKey, int $amountMinor, string $receivedAt, string $bankReference, string $reason): string
    {
        OrderService::requireCapability($actor,'PLATFORM.PAYMENT.MANAGE');
        OrderService::requireCapability($actor,'PLATFORM.PAYMENT.RECONCILE');
        if (!Uuid::isValid($requestKey)) throw new RuntimeException('Invalid confirmation identifier.');
        $bankReference=trim($bankReference); $reason=trim($reason);
        if ($amountMinor<=0 || mb_strlen($bankReference)<3 || mb_strlen($bankReference)>200 || mb_strlen($reason)<5 || mb_strlen($reason)>2000) {
            throw new RuntimeException('Enter the exact received amount, a bank reference and a written reason.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/',$receivedAt)) throw new RuntimeException('Enter the received date and time with a timezone offset.');
        try { $received=new DateTimeImmutable($receivedAt); }
        catch (\Exception) { throw new RuntimeException('Enter a valid date and time received.'); }
        if ($received>$this->clock->now()) throw new RuntimeException('The received time cannot be in the future.');
        [$paymentId,$currency]=$this->transactions->run(function () use ($actor,$orderId,$requestKey,$amountMinor,$received,$bankReference,$reason): array {
            $order=$this->records->order($orderId);
            $this->records->lockPurchaser((int)$order['purchaser_user_id']);
            $order=$this->records->order($orderId,true);
            $existing=$this->records->paymentForRequest($orderId,$requestKey);
            if ($existing!==null) {
                if ($existing['gateway']!=='manual_bank' || $this->records->manualEvidence((string)$existing['id'])===null) throw new RuntimeException('This confirmation identifier belongs to another payment.');
                return [(string)$existing['id'],(string)$order['currency']];
            }
            if (!in_array($order['state'],['awaiting_payment','cancelled'],true) || $this->records->paymentMethod($orderId)!=='eft') throw new RuntimeException('This order cannot accept an EFT confirmation.');
            if ($amountMinor!==(int)$order['total_minor']) throw new RuntimeException('Full payment must match the exact order total; record discrepancies for review instead of fulfilling.');
            foreach ($this->records->payments($orderId) as $payment) {
                if (in_array($payment['state'],['created','pending','paid','partially_refunded','refunded'],true)) throw new RuntimeException('A payment is already recorded or being processed for this order.');
            }
            $now=$this->clock->now()->format(DATE_ATOM);
            $id=$this->records->createPayment($order,$requestKey,'manual_bank',$now);
            $this->records->setPaymentState($id,$this->transitions->apply('payment','created','submit'),null);
            $this->records->recordManualEvidence($id,$orderId,$amountMinor,(string)$order['currency'],$received->format(DATE_ATOM),$bankReference,$reason,$actor->id,$now);
            $this->records->audit($orderId,$actor->id,'payment.bank_evidence_confirmed',['payment_id'=>$id,'amount_minor'=>$amountMinor,'received_at'=>$received->format(DATE_ATOM),'bank_reference'=>$bankReference,'reason'=>$reason],$now);
            return [$id,(string)$order['currency']];
        });
        if (in_array($this->records->payment($paymentId)['state'],['created','pending'],true)) {
            $evidence=$this->records->manualEvidence($paymentId) ?? throw new RuntimeException('Bank evidence is missing.');
            $this->payments->confirm('manual_bank',new PaymentResult($paymentId,'paid',Money::strictMinorUnits((int)$evidence['amount_minor'],$currency),(string)$evidence['bank_reference'],'bank:'.$paymentId,'ADMIN confirmed bank receipt'));
        }
        return $paymentId;
    }

    public function releasePaidReview(CurrentUser $actor, int $orderId, string $reason): void
    {
        OrderService::requireCapability($actor,'PLATFORM.PAYMENT.RECONCILE');
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>2000) throw new RuntimeException('Record a written reconciliation reason.');
        $this->transactions->run(function () use ($actor,$orderId,$reason): void {
            $order=$this->records->order($orderId);
            $this->records->lockPurchaser((int)$order['purchaser_user_id']);
            $order=$this->records->order($orderId,true);
            if ($order['state']!=='manual_review' || $this->records->paidTotal($orderId)<(int)$order['total_minor']) throw new RuntimeException('Only a fully paid order in manual review can be released.');
            $order['state']=$this->transitions->apply('order','manual_review','release_review_paid');
            $this->records->setOrderState($orderId,$order['state']);
            if (!$this->fulfilment->fulfil($order)) throw new RuntimeException('The purchase can no longer be fulfilled automatically. Resolve its course, learner or request relationship first.');
            $this->records->audit($orderId,$actor->id,'payment.review_released',['reason'=>$reason],$this->clock->now()->format(DATE_ATOM));
        });
    }
}
