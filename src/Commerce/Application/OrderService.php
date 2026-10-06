<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Bundle\BundleRules;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Domain\PromotionRejected;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use RuntimeException;

/** Purchaser-scoped cart and immutable order placement; no gateway call occurs in this transaction. */
final class OrderService
{
    public function __construct(
        private readonly CommerceRepository $records,
        private readonly TransactionManager $transactions,
        private readonly CommercePolicy $policy,
        private readonly TransitionService $transitions,
        private readonly ClockInterface $clock,
        private readonly PromotionService $promotions
    ) {}

    public static function requireCapability(CurrentUser $actor, string $permission): void
    {
        if (!$actor->hasPermission($permission)) throw new AccessDeniedHttpException('You cannot perform this commerce action.');
    }

    /**
     * The purchaser's open cart, priced: its course lines ('items'), its bundle lines ('bundles', each
     * with its courses as they are now), its promotion as it applies now ('promotion': the code, the
     * calculated discount or the reason it no longer applies) and a quote covering all of them.
     *
     * @return array<string,mixed>
     */
    public function cart(CurrentUser $actor): array
    {
        self::requireCapability($actor,'COMMERCE.CART.VIEW');
        return $this->priced($actor,false);
    }

    /** @return array<string,mixed> */
    private function priced(CurrentUser $actor, bool $forPlacement): array
    {
        $cart=$this->records->cart($actor->id);
        $cart['items']=$this->records->cartItems((int)$cart['id']);
        $cart['bundles']=$this->bundleLines((int)$cart['id']);
        $cart['promotion']=$this->promotions->forCart($cart,$actor->id,$forPlacement);
        $cart['quote']=$this->quote($cart);
        return $cart;
    }

    public function changeCart(CurrentUser $actor, int $variantId, bool $remove = false): void
    {
        self::requireCapability($actor,'COMMERCE.CART.MANAGE');
        $this->transactions->run(function() use ($actor,$variantId,$remove): void {
            $this->records->lockPurchaser($actor->id);
            $cart=$this->records->cart($actor->id);
            if (!$remove) {
                $offer=$this->records->offer($variantId);
                $this->validateOffer($offer,$actor->id);
                $items=$this->records->cartItems((int)$cart['id']);
                if (count($items)>=50) throw new RuntimeException('Place this order before adding more courses.');
                foreach($items as $item) {
                    if ((int)$item['course_id']===(int)$offer['course_id'] && (int)$item['id']!==$variantId) {
                        throw new RuntimeException('Choose one access period per course.');
                    }
                }
                // A course a bundle in the cart already includes would be paid for twice.
                foreach($this->bundleLines((int)$cart['id']) as $bundle) {
                    foreach($bundle['courses'] as $course) {
                        if ((int)$course['course_id']===(int)$offer['course_id']) throw new RuntimeException((string)$offer['title'].' is already included in '.$bundle['title'].' in your cart.');
                    }
                }
            }
            $this->records->changeCart((int)$cart['id'],$variantId,$remove);
        });
    }

    /**
     * Adds or removes a bundle. A course line the bundle already includes is taken out of the cart,
     * so nothing is paid for twice; the titles of the courses taken out are returned to explain it.
     * Two bundles that share courses may be bought together: each is its own product.
     *
     * @return list<string>
     */
    public function changeBundle(CurrentUser $actor, int $offerId, bool $remove = false): array
    {
        self::requireCapability($actor,'COMMERCE.CART.MANAGE');
        return $this->transactions->run(function() use ($actor,$offerId,$remove): array {
            $this->records->lockPurchaser($actor->id);
            $cart=$this->records->cart($actor->id);
            $removed=[];
            if (!$remove) {
                $offer=$this->records->bundleOffer($offerId);
                $courses=$this->records->bundleCourses((int)$offer['bundle_id']);
                $this->validateBundle($offer,$courses,$actor->id);
                if (count($this->records->cartBundles((int)$cart['id']))>=20) throw new RuntimeException('Place this order before adding more bundles.');
                $included=array_map(static fn(array $c): int => (int)$c['course_id'],$courses);
                foreach($this->records->cartItems((int)$cart['id']) as $item) {
                    if (!in_array((int)$item['course_id'],$included,true)) continue;
                    $this->records->changeCart((int)$cart['id'],(int)$item['id'],true);
                    $removed[]=(string)$item['title'];
                }
            }
            $this->records->changeCartBundle((int)$cart['id'],$offerId,$remove);
            return $removed;
        });
    }

    /**
     * The cart's bundle lines, each with its courses as they are now.
     *
     * @return list<array<string,mixed>>
     */
    private function bundleLines(int $cartId): array
    {
        $lines=[];
        foreach($this->records->cartBundles($cartId) as $line) {
            $line['courses']=$this->records->bundleCourses((int)$line['bundle_id']);
            $lines[]=$line;
        }
        return $lines;
    }

    /**
     * The bundle can be bought now (one rule with the catalogue: BundleRules) and is not already in
     * an order awaiting payment. Owning some of its courses does not change the bundle or its price.
     *
     * @param array<string,mixed> $offer
     * @param list<array<string,mixed>> $courses
     */
    public function validateBundle(array $offer, array $courses, int $userId): void
    {
        $reason=BundleRules::unavailableReason($offer,$courses,$this->clock->now());
        if ($reason!==null) throw new RuntimeException($reason);
        if ($offer['currency_code']!=='ZAR') throw new RuntimeException('This checkout currently supports ZAR offers only.');
        if ($this->records->hasPayableBundle($userId,(int)$offer['bundle_id'])) throw new RuntimeException('An existing order already contains this bundle.');
    }

    /** @param array<string,mixed> $cart */
    private function quote(array $cart): string
    {
        $discount=$cart['promotion']['discount'] ?? null;
        return hash('sha256',json_encode([$cart['id'],$cart['revision'],$cart['items'],$cart['bundles'] ?? [],$discount?->quoteKey()],JSON_THROW_ON_ERROR));
    }

    /**
     * A promo code on the cart that no longer applies stops placement with the reason, so the
     * purchaser can see the corrected total before ordering; it is never honoured because it once
     * applied.
     *
     * @param array<string,mixed> $cart a priced cart
     */
    public static function requireValidPromotion(array $cart): void
    {
        $promotion=(array)($cart['promotion'] ?? []);
        if (($promotion['rejection'] ?? null)!==null) throw new PromotionRejected((string)$promotion['rejection'],(string)$promotion['code']);
    }

    /** @param array<string,mixed> $offer */
    public function validateOffer(array $offer, int $userId): void
    {
        if (!(bool)$offer['is_active'] || $offer['course_status']!=='published') throw new RuntimeException('This offer is no longer available.');
        if ($offer['currency_code']!=='ZAR') throw new RuntimeException('This checkout currently supports ZAR offers only.');
        if ($this->records->hasOpenEnrolment($userId,(int)$offer['course_id'])) throw new RuntimeException('This course is already in your library.');
        if ($this->records->hasPayableCourse($userId,(int)$offer['course_id'])) throw new RuntimeException('An existing order already contains this course.');
    }

    /**
     * Captures billing and consent evidence independently of mutable profile/course data. The
     * billing details are copied into the order's snapshot as they are now; a replayed placement
     * returns the existing order and its original snapshot.
     *
     * A promo code is evaluated again here, under its row lock, and its use is taken by inserting
     * the order in the same transaction. The discount is recorded beside the lines, never in their
     * prices: each line keeps its price and records its share of the discount.
     *
     * @param array<string,mixed> $checkout
     */
    public function place(CurrentUser $actor, int $cartId, string $quote, BillingDetails $billing, bool $accepted, array $checkout = []): int
    {
        self::requireCapability($actor,'COMMERCE.CHECKOUT.START');
        return $this->transactions->run(function() use ($actor,$cartId,$quote,$billing,$accepted,$checkout): int {
            $this->records->lockPurchaser($actor->id);
            $existing=$this->records->orderForCart($cartId,$actor->id);
            if ($existing!==null) return (int)$existing['id'];
            self::requireCapability($actor,'COMMERCE.CART.VIEW');
            $cart=$this->priced($actor,true);
            self::requireValidPromotion($cart);
            if ((int)$cart['id']!==$cartId || !hash_equals((string)$cart['quote'],$quote)) throw new RuntimeException('Your cart or prices changed. Review the updated checkout.');
            if (!$accepted) throw new RuntimeException('Accept the purchase terms before placing your order.');
            if ($cart['items']===[] && $cart['bundles']===[]) throw new RuntimeException('Your cart is empty.');
            $discount=$cart['promotion']['discount'];
            $subtotal=Money::strictMinorUnits(0,'ZAR'); $lines=[];
            foreach($cart['items'] as $offer) {
                $this->validateOffer($offer,$actor->id);
                $amount=Money::strictMinorUnits((int)$offer['price_minor_units'],(string)$offer['currency_code']);
                if ($amount->isFree()) throw new RuntimeException('Remove free courses from the cart and accept them directly.');
                $subtotal=$subtotal->plus($amount);
                $lineDiscount=$discount?->lineDiscount('course:'.(int)$offer['id']) ?? 0;
                $lines[]=['variant_id'=>(int)$offer['id'],'course_id'=>(int)$offer['course_id'],'course_title'=>(string)$offer['title'],'revision_policy'=>'purchased_course_with_published_edits','access_period_seconds'=>(int)$offer['access_period_seconds'],'quantity'=>1,'unit_price_minor'=>$amount->minorUnits,'line_total_minor'=>$amount->minorUnits,'currency'=>$amount->currency,'tax_minor'=>0,'tax_rate'=>'0','discount_minor'=>$lineDiscount,'paid_minor'=>$amount->minorUnits-$lineDiscount,'promotion_eligible'=>$discount!==null && array_key_exists('course:'.(int)$offer['id'],$discount->lineDiscounts),'fulfilment_type'=>'individual_access','terms_version'=>$this->policy->termsVersion,'activation_deadline_days'=>$this->policy->activationDeadlineDays];
            }
            foreach($cart['bundles'] as $bundle) {
                $this->validateBundle($bundle,$bundle['courses'],$actor->id);
                $amount=Money::strictMinorUnits((int)$bundle['price_minor_units'],(string)$bundle['currency_code']);
                $subtotal=$subtotal->plus($amount);
                $key='bundle:'.(int)$bundle['id'];
                $lineDiscount=$discount?->lineDiscount($key) ?? 0;
                // The bundle as sold: its price, access period and courses now. Fulfilment and every
                // document read this, never the bundle's current definition.
                $lines[]=['fulfilment_type'=>'bundle','bundle_id'=>(int)$bundle['bundle_id'],'bundle_offer_id'=>(int)$bundle['id'],'bundle_title'=>(string)$bundle['title'],'bundle_slug'=>(string)$bundle['slug'],
                    'courses'=>array_map(static fn(array $c): array => ['course_id'=>(int)$c['course_id'],'title'=>(string)$c['title'],'slug'=>(string)$c['slug']],$bundle['courses']),
                    'access_period_seconds'=>(int)$bundle['access_period_seconds'],'quantity'=>1,'unit_price_minor'=>$amount->minorUnits,'line_total_minor'=>$amount->minorUnits,'currency'=>$amount->currency,
                    'tax_minor'=>0,'tax_rate'=>'0','discount_minor'=>$lineDiscount,'paid_minor'=>$amount->minorUnits-$lineDiscount,'promotion_eligible'=>$discount!==null && array_key_exists($key,$discount->lineDiscounts),
                    'revision_policy'=>'purchased_course_with_published_edits','terms_version'=>$this->policy->termsVersion,'activation_deadline_days'=>$this->policy->activationDeadlineDays];
            }
            $discountMinor=$discount->discountMinor ?? 0;
            if ($discount!==null && $discount->currency!==$subtotal->currency) throw new RuntimeException('Your cart or prices changed. Review the updated checkout.');
            $total=$subtotal->minorUnits-$discountMinor;
            $now=$this->clock->now();
            $snapshot=['purchaser_user_id'=>$actor->id,'purchaser_name'=>$actor->displayName,'purchaser_email'=>$actor->primaryEmail,'billing'=>$billing->toSnapshot(),'items'=>$lines,'subtotal_minor'=>$subtotal->minorUnits,'discount_minor'=>$discountMinor,'promotion'=>$discount?->toSnapshot(),'total_minor'=>$total,'currency'=>$subtotal->currency,'payment_method'=>$checkout['payment_method'] ?? 'dummy','invoice_email'=>$checkout['invoice_email'] ?? false,'tax_enabled'=>false,'terms_version'=>$this->policy->termsVersion,'consent'=>['accepted_at'=>$now->format(DATE_ATOM),'session_id'=>$actor->sessionPublicId,'immediate_service'=>false],'activation_deadline_days'=>$this->policy->activationDeadlineDays];
            $id=$this->records->place($cartId,$actor->id,$total,$subtotal->currency,$snapshot,$now->format(DATE_ATOM),$now->modify('+'.$this->policy->paymentDueDays.' days')->format(DATE_ATOM),$discount?->promotion->id,$discountMinor);
            foreach($lines as $line) $this->records->addOrderItem($id,$actor->id,$line);
            $this->records->document($id,'invoice','order:'.$id,$snapshot,$now->format(DATE_ATOM));
            $this->records->selectPaymentMethod($id, (string) ($checkout['payment_method'] ?? 'dummy'));
            if ($checkout['invoice_email'] ?? false) $this->records->enqueue('invoice:'.$id, 'invoice.email_requested', ['order_id'=>$id], $now->format(DATE_ATOM));
            $this->records->setOrderState($id,$this->transitions->apply('order','placed','await_payment'));
            $this->records->audit($id,$actor->id,'order.placed',['total_minor'=>$total]+($discount===null?[]:['promotion_id'=>$discount->promotion->id,'discount_minor'=>$discountMinor]),$now->format(DATE_ATOM));
            return $id;
        });
    }

    /** @return array<string,mixed> */
    public function view(CurrentUser $actor, int $orderId): array
    {
        self::requireCapability($actor,'COMMERCE.ORDER.VIEW');
        $order=$this->records->order($orderId);
        if ((int)$order['purchaser_user_id']!==$actor->id) throw new AccessDeniedHttpException('This order belongs to another purchaser.');
        $order['details']=CommerceRepository::decode((string)$order['snapshot']);
        $order['documents']=$this->records->documents($orderId);
        $order['payments']=$this->records->payments($orderId);
        return $order;
    }

    public function cancelDue(int $orderId): bool
    {
        return $this->transactions->run(function() use($orderId): bool {
            $row=$this->records->order($orderId);
            $this->records->lockPurchaser((int)$row['purchaser_user_id']);
            $row=$this->records->order($orderId,true);
            if ($row['state']!=='awaiting_payment' || new \DateTimeImmutable((string)$row['payment_due_at'])>$this->clock->now()) return false;
            $this->records->setOrderState($orderId,$this->transitions->apply('order',(string)$row['state'],'cancel'));
            $this->records->audit($orderId,null,'order.payment_deadline_elapsed',[],$this->clock->now()->format(DATE_ATOM));
            return true;
        });
    }
}
