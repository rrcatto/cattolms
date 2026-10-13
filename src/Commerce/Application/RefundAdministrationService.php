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
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Uuid;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Explicit ADMIN refund decisions; credited value enters the purchaser's append-only Account Funds ledger.
 *
 * A line can return at most what was paid for it: its price less its share of the order's promotion
 * discount (commerce_order_items.discount_minor, allocated at placement). The undiscounted catalogue
 * price is never the basis of a refund.
 *
 * Refunding all that was paid for a line revokes the entitlement sources that line created and
 * nothing else; each course's access is then worked out again from its remaining sources, so a course
 * another purchase, bundle, ADMIN assignment, company credit or free access still covers stays open.
 * A bundle line is refunded as the product that was bought, never course by course.
 *
 * The Refund Policy's rule (RefundRule) is enforced here: a refund is refused once the learner has
 * started the course (any course, for a bundle) or seven days have passed since the purchase, and a
 * company credit refund once seven days have passed since the credits were bought. ADMIN may
 * approve a refused refund with a written override reason; what the rule refused and that reason
 * are recorded with the refund, which is append-only.
 */
final class RefundAdministrationService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransactionManager $transactions,
        private readonly TransitionService $transitions, private readonly ClockInterface $clock, private readonly AnalyticsEventRecorder $analytics,
        private readonly AccessService $access, private readonly FinancialDocuments $documents) {}

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
        return RefundRule::refusals($purchasedAt,$this->clock->now(),$started,$item['product_type']==='bundle',$company);
    }

    /**
     * $overrideReason is ADMIN's written reason for approving a refund the Refund Policy refuses. It
     * is required then, and ignored (nothing is recorded as an override) when the rule allows the refund.
     */
    public function approve(CurrentUser $actor, int $orderId, int $itemId, string $requestKey, string $basis, string $reason, int $quantity, int $amountMinor, string $overrideReason = ''): int
    {
        OrderService::requireCapability($actor,'PLATFORM.REFUND.MANAGE');
        if (!Uuid::isValid($requestKey)) throw new RuntimeException('Invalid refund identifier.');
        if (!in_array($basis,['statutory','service_failure','goodwill','voluntary'],true)) throw new RuntimeException('Select a refund basis.');
        $reason=trim($reason);
        if (mb_strlen($reason)<5 || mb_strlen($reason)>2000) throw new RuntimeException('Record the refund decision and its reason.');
        $overrideReason=trim($overrideReason);
        return $this->transactions->run(function () use ($actor,$orderId,$itemId,$requestKey,$basis,$reason,$quantity,$amountMinor,$overrideReason): int {
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
            $refusals=$this->ruleRefusals($order,$item);
            if ($refusals!==[]) {
                if ($overrideReason==='') throw new RuntimeException('The Refund Policy does not allow this refund. '.implode(' ',$refusals).' To approve it anyway, record an override reason.');
                if (mb_strlen($overrideReason)<5 || mb_strlen($overrideReason)>2000) throw new RuntimeException('Record why the Refund Policy is overridden, in 5 to 2,000 characters.');
            }
            $policyRefusal=$refusals===[] ? null : implode(' ',$refusals);
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
                $remaining=self::paidAmount($item)-$this->records->refundedAmount($orderId,$itemId);
                if ($amountMinor>$remaining) throw new RuntimeException('The refund exceeds this item’s unrefunded paid amount.');
            }
            $id=$this->records->createRefund($requestKey,$orderId,$itemId,$quantity,$amountMinor,(string)$order['currency'],$basis,$reason,$actor->id,$now,$policyRefusal,$policyRefusal===null ? null : $overrideReason);
            $this->records->creditRefundFunds($id,$order['company_id']===null?(int)$order['purchaser_user_id']:null,$order['company_id']===null?null:(int)$order['company_id'],(string)$order['currency'],$amountMinor,$now);
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
            $this->documents->issueCreditNote($orderId,$id,$snapshot,$now,$actor->id);
            $fullyRefunded=$this->records->refundedAmount($orderId,$itemId)>=self::paidAmount($item);
            $access=['revoked'=>0,'kept'=>0];
            if ($order['company_id']===null && $fullyRefunded) {
                $sources=$this->records->sourcesForItem($itemId);
                $open=$this->access->revoke($sources,'refund:'.$id);
                $access=['revoked'=>count($sources),'kept'=>count(array_filter($open))];
            }
            if ($bundle) $this->recordBundleRefund($order,$item,$id,$amountMinor,$fullyRefunded,$access);
            else $this->recordRefund($order,$item,$id,$quantity,$amountMinor,$fullyRefunded);
            $total=$this->records->refundedAmount($orderId);
            $transition=$total>=(int)$order['total_minor']?'mark_refunded':'mark_partially_refunded';
            $this->records->setOrderState($orderId,$this->transitions->apply('order',(string)$order['state'],$transition));
            $this->records->audit($orderId,$actor->id,'refund.approved',['refund_id'=>$id,'order_item_id'=>$itemId,'quantity'=>$quantity,'amount_minor'=>$amountMinor,'basis'=>$basis,'reason'=>$reason]+($policyRefusal===null?[]:['policy_refusal'=>$policyRefusal,'override_reason'=>$overrideReason])+($bundle?['bundle_access'=>$access]:[]),$now);
            return $id;
        });
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
