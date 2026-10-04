<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventType;
use CattoLearning\Analytics\AnalyticsSource;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;
use RuntimeException;

/** The only commerce writer of paid learning entitlements; payment service holds purchaser/order locks. */
final class FulfilmentService
{
    public function __construct(private readonly CommerceRepository $records, private readonly TransitionService $transitions, private readonly AccessService $access, private readonly TransactionManager $transactions, private readonly OrderService $orders, private readonly ClockInterface $clock, private readonly CompanyCreditFulfilment $companyCredits, private readonly AnalyticsEventRecorder $analytics) {}

    /** @param array<string,mixed> $order */
    public function fulfil(array $order): bool
    {
        if ($order['state']!=='paid') throw new RuntimeException('Only a fully paid order can be fulfilled.');
        $items=$this->records->items((int)$order['id']);
        $this->recordPurchases($order,$items);
        if ($order['company_id'] !== null) {
            if (!$this->companyCredits->ready($order,$items)) return false;
            $this->companyCredits->fulfil($order,$items);
            $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','fulfil'));
            return true;
        }
        foreach($items as $item) {
            if (!$this->records->fulfilledItem((int)$item['id']) && $this->records->hasOpenEnrolment((int)$item['beneficiary_user_id'],(int)$item['course_id'])) return false;
        }
        foreach($items as $item) {
            if ($this->records->fulfilledItem((int)$item['id'])) continue;
            $snapshot=CommerceRepository::decode((string)$item['snapshot']);
            $now=$this->clock->now();
            $enrolment=$this->records->createEntitlement((int)$item['id'],(int)$item['beneficiary_user_id'],(int)$item['course_id'],(int)$item['access_period_seconds'],'paid_order',$snapshot,$now->format(DATE_ATOM),$now->modify('+'.(int)$snapshot['activation_deadline_days'].' days')->format(DATE_ATOM));
            $this->records->audit((int)$order['id'],null,'entitlement.created',['enrolment_id'=>$enrolment,'order_item_id'=>$item['id']],$now->format(DATE_ATOM));
            $this->records->enqueue('entitlement:'.$enrolment,'entitlement.created',['enrolment_id'=>$enrolment,'user_id'=>$item['beneficiary_user_id'],'course_title'=>$snapshot['course_title']],$now->format(DATE_ATOM));
        }
        $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','fulfil'));
        return true;
    }

    /**
     * Each line of a paid order is one course purchase, recorded once whatever replays the payment
     * confirmation or review release that brought the order here (both call fulfil() inside their
     * transaction). Only ids and the line's own figures are recorded, never payment details. A
     * malformed analytics value is logged and skipped rather than failing a paid order.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    private function recordPurchases(array $order, array $items): void
    {
        $company=$order['company_id']!==null;
        foreach ($items as $item) {
            try {
                $this->analytics->record(AnalyticsEventType::CoursePurchased, AnalyticsSource::Checkout,
                    ['user_id'=>$company?(int)$order['purchaser_user_id']:(int)$item['beneficiary_user_id'],'course_id'=>(int)$item['course_id'],'order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id']],
                    ['quantity'=>(int)($item['quantity'] ?? 1),'access_period_seconds'=>(int)$item['access_period_seconds'],'amount_minor'=>(int)$item['amount_minor'],'currency'=>(string)$order['currency'],'purchaser'=>$company?'company':'individual','company_id'=>$company?(int)$order['company_id']:null],
                    'course_purchased:order_item:'.(int)$item['id']);
            } catch (\InvalidArgumentException $exception) {
                error_log('Purchase analytics for order item '.(int)$item['id'].' was not recorded: '.$exception->getMessage());
            }
        }
    }

    public function acceptFree(CurrentUser $actor, int $variantId): int
    {
        OrderService::requireCapability($actor,'LEARNING.COURSE.START');
        return $this->transactions->run(function() use($actor,$variantId): int {
            $this->records->lockPurchaser($actor->id);
            $offer=$this->records->offer($variantId);
            $this->orders->validateOffer($offer,$actor->id);
            if ((int)$offer['price_minor_units']!==0) throw new RuntimeException('This course requires checkout.');
            $now=$this->clock->now()->format(DATE_ATOM);
            $id=$this->records->createEntitlement(null,$actor->id,(int)$offer['course_id'],(int)$offer['access_period_seconds'],'free',['course_title'=>$offer['title'],'variant_id'=>$variantId,'access_period_seconds'=>$offer['access_period_seconds']],$now,$now);
            $this->access->reconcile($id);
            $this->records->audit(null,$actor->id,'entitlement.free_accepted',['enrolment_id'=>$id],$now);
            return $id;
        });
    }
}
