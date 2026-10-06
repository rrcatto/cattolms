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
    public function __construct(private readonly CommerceRepository $records, private readonly TransitionService $transitions, private readonly AccessService $access, private readonly TransactionManager $transactions, private readonly OrderService $orders, private readonly ClockInterface $clock, private readonly CompanyCreditFulfilment $companyCredits, private readonly AnalyticsEventRecorder $analytics, private readonly PromotionService $promotions) {}

    /** @param array<string,mixed> $order */
    public function fulfil(array $order): bool
    {
        if ($order['state']!=='paid') throw new RuntimeException('Only a fully paid order can be fulfilled.');
        $items=$this->records->items((int)$order['id']);
        $this->recordPurchases($order,$items);
        // The order is paid: its promotion use is redeemed now, once, before anything can hold it for review.
        $this->promotions->redeem($order);
        if ($order['company_id'] !== null) {
            if (!$this->companyCredits->ready($order,$items)) return false;
            $this->companyCredits->fulfil($order,$items);
            $this->records->setOrderState((int)$order['id'],$this->transitions->apply('order','paid','fulfil'));
            return true;
        }
        foreach($items as $item) {
            if ($item['product_type']!=='individual_access') continue;
            if (!$this->records->fulfilledItem((int)$item['id']) && $this->records->hasOpenEnrolment((int)$item['beneficiary_user_id'],(int)$item['course_id'])) return false;
        }
        foreach($items as $item) {
            if ($item['product_type']==='bundle') {
                $this->grantBundle($order,$item);
                continue;
            }
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
     * Grants a paid bundle line's courses: the courses in its snapshot, as sold, never the bundle's
     * current composition. Every course gets its own bundle entitlement source (the line as its order
     * item) for the line's access period, whether or not the learner already has the course: a bundle
     * always grants what was bought. A learner without the course gets a new enrolment for it; a
     * learner who has it keeps their one enrolment, which gains the bundle as another source (an
     * enrolment created outside commerce first records its own term as its origin source, so neither
     * replaces the other). Each grant is one commerce_bundle_grants row naming its source and whether
     * another source already granted the course, so a replay grants nothing twice and a refund can
     * revoke exactly this line's sources. All in the fulfilment transaction: every course or none.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $item
     */
    private function grantBundle(array $order, array $item): void
    {
        $snapshot=CommerceRepository::decode((string)$item['snapshot']);
        $user=(int)$item['beneficiary_user_id'];
        $now=$this->clock->now();
        $deadline=$now->modify('+'.(int)$snapshot['activation_deadline_days'].' days')->format(DATE_ATOM);
        $shared=[];
        foreach($this->records->bundleGrants((int)$item['id']) as $grant) $shared[(int)$grant['course_id']]=(bool)$grant['shared_at_grant'];
        foreach((array)$snapshot['courses'] as $course) {
            $courseId=(int)$course['course_id'];
            if (array_key_exists($courseId,$shared)) continue;
            $enrolment=$this->records->openEnrolment($user,$courseId);
            $held=$enrolment!==null;
            if ($held) $this->records->recordOriginSource($enrolment,$now->format(DATE_ATOM));
            else $enrolment=$this->records->createEnrolment($user,$courseId,(int)$item['access_period_seconds'],'bundle',(int)$item['id'],$now->format(DATE_ATOM));
            $source=$this->records->addSource((int)$item['id'],$enrolment,'bundle',(int)$item['access_period_seconds'],
                ['course_id'=>$courseId,'course_title'=>(string)$course['title'],'bundle_id'=>(int)$snapshot['bundle_id'],'bundle_title'=>(string)$snapshot['bundle_title'],
                    'order_item_id'=>(int)$item['id'],'access_period_seconds'=>(int)$item['access_period_seconds'],'activation_deadline_days'=>(int)$snapshot['activation_deadline_days']],
                $now->format(DATE_ATOM),$deadline);
            $this->records->recordBundleGrant((int)$item['id'],$courseId,$enrolment,$source,$held,$now->format(DATE_ATOM));
            $this->records->audit((int)$order['id'],null,'entitlement.created',['enrolment_id'=>$enrolment,'entitlement_id'=>$source,'order_item_id'=>(int)$item['id'],'course_id'=>$courseId,'bundle_id'=>(int)$snapshot['bundle_id'],'shared'=>$held],$now->format(DATE_ATOM));
            $this->records->enqueue('entitlement:'.$source,'entitlement.created',['enrolment_id'=>$enrolment,'user_id'=>$user,'course_title'=>(string)$course['title']],$now->format(DATE_ATOM));
            // A learner who already started the course has this source running from now.
            $this->access->reconcile($enrolment);
            $shared[$courseId]=$held;
        }
        $this->analytics->recordSafely(AnalyticsEventType::BundlePurchased, AnalyticsSource::Checkout,
            ['user_id'=>$user,'order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id']],
            ['bundle_id'=>(int)$snapshot['bundle_id'],'amount_minor'=>(int)$item['amount_minor']-(int)$item['discount_minor'],'discount_minor'=>(int)$item['discount_minor'],'currency'=>(string)$order['currency'],
                'course_count'=>count((array)$snapshot['courses']),'courses_granted'=>count($shared),'courses_already_held'=>count(array_filter($shared)),'access_period_seconds'=>(int)$item['access_period_seconds']],
            'bundle_purchased:order_item:'.(int)$item['id']);
    }

    /**
     * Each course line of a paid order is one course purchase, recorded once whatever replays the payment
     * confirmation or review release that brought the order here (both call fulfil() inside their
     * transaction). Only ids and the line's own figures are recorded, never payment details; the
     * amount is what the line was paid, its price less its share of any promotion discount. A
     * malformed analytics value is logged and skipped rather than failing a paid order. A bundle line
     * is not a purchase of its courses: it records bundle_purchased when its courses are granted.
     *
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    private function recordPurchases(array $order, array $items): void
    {
        $company=$order['company_id']!==null;
        foreach ($items as $item) {
            if ($item['product_type']==='bundle') continue;
            try {
                $this->analytics->record(AnalyticsEventType::CoursePurchased, AnalyticsSource::Checkout,
                    ['user_id'=>$company?(int)$order['purchaser_user_id']:(int)$item['beneficiary_user_id'],'course_id'=>(int)$item['course_id'],'order_id'=>(int)$order['id'],'order_item_id'=>(int)$item['id']],
                    ['quantity'=>(int)($item['quantity'] ?? 1),'access_period_seconds'=>(int)$item['access_period_seconds'],'amount_minor'=>(int)$item['amount_minor']-(int)($item['discount_minor'] ?? 0),'discount_minor'=>(int)($item['discount_minor'] ?? 0),'currency'=>(string)$order['currency'],'purchaser'=>$company?'company':'individual','company_id'=>$company?(int)$order['company_id']:null],
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
