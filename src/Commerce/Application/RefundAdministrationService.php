<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Uuid;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/** Explicit ADMIN refund decisions; credited value enters the purchaser's append-only Account Funds ledger. */
final class RefundAdministrationService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions,
        private readonly TransitionService $transitions, private readonly ClockInterface $clock) {}

    public function approve(CurrentUser $actor, int $orderId, int $itemId, string $requestKey, string $basis, string $reason, int $quantity, int $amountMinor): int
    {
        OrderService::requireCapability($actor,'PLATFORM.REFUND.MANAGE');
        if (!Uuid::isValid($requestKey)) throw new RuntimeException('Invalid refund identifier.');
        if (!in_array($basis,['statutory','service_failure','goodwill','voluntary'],true)) throw new RuntimeException('Select a refund basis.');
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>2000) throw new RuntimeException('Record the refund decision and its reason.');
        return $this->transactions->run(function () use ($actor,$orderId,$itemId,$requestKey,$basis,$reason,$quantity,$amountMinor): int {
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
            $now=$this->clock->now()->format(DATE_ATOM);
            if ($order['company_id']!==null) {
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
                $remaining=(int)$item['amount_minor']-$this->records->refundedAmount($orderId,$itemId);
                if ($amountMinor>$remaining) throw new RuntimeException('The refund exceeds this item’s unrefunded paid amount.');
            }
            $id=$this->records->createRefund($requestKey,$orderId,$itemId,$quantity,$amountMinor,(string)$order['currency'],$basis,$reason,$actor->id,$now);
            $this->records->creditRefundFunds($id,$order['company_id']===null?(int)$order['purchaser_user_id']:null,$order['company_id']===null?null:(int)$order['company_id'],(string)$order['currency'],$amountMinor,$now);
            $itemSnapshot=CommerceRepository::decode((string)$item['snapshot']);
            $snapshot=CommerceRepository::decode((string)$order['snapshot']);
            $snapshot['items']=[['course_title'=>$itemSnapshot['course_title'],'access_period_seconds'=>$item['access_period_seconds'],'quantity'=>$quantity,'line_total_minor'=>$amountMinor,'currency'=>$order['currency']]];
            $snapshot['total_minor']=$amountMinor;
            $snapshot['refund_id']=$id;
            $snapshot['refund_basis']=$basis;
            $snapshot['refund_reason']=$reason;
            $this->records->document($orderId,'credit_note','refund:'.$id,$snapshot,$now);
            if ($order['company_id']===null && $this->records->refundedAmount($orderId,$itemId)>=(int)$item['amount_minor']) {
                $this->records->revokeRefundedEntitlement($itemId,$now);
            }
            $total=$this->records->refundedAmount($orderId);
            $transition=$total>=(int)$order['total_minor']?'mark_refunded':'mark_partially_refunded';
            $this->records->setOrderState($orderId,$this->transitions->apply('order',(string)$order['state'],$transition));
            $this->records->audit($orderId,$actor->id,'refund.approved',['refund_id'=>$id,'order_item_id'=>$itemId,'quantity'=>$quantity,'amount_minor'=>$amountMinor,'basis'=>$basis,'reason'=>$reason],$now);
            return $id;
        });
    }
}
