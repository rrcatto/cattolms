<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
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
        private readonly ClockInterface $clock
    ) {}

    public static function requireCapability(CurrentUser $actor, string $permission): void
    {
        if (!$actor->hasPermission($permission)) throw new AccessDeniedHttpException('You cannot perform this commerce action.');
    }

    /** @return array<string,mixed> */
    public function cart(CurrentUser $actor): array
    {
        self::requireCapability($actor,'COMMERCE.CART.VIEW');
        $cart=$this->records->cart($actor->id);
        $cart['items']=$this->records->cartItems((int)$cart['id']);
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
            }
            $this->records->changeCart((int)$cart['id'],$variantId,$remove);
        });
    }

    /** @param array<string,mixed> $cart */
    private function quote(array $cart): string
    {
        return hash('sha256',json_encode([$cart['id'],$cart['revision'],$cart['items']],JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $offer */
    public function validateOffer(array $offer, int $userId): void
    {
        if (!(bool)$offer['is_active'] || $offer['course_status']!=='published') throw new RuntimeException('This offer is no longer available.');
        if ($offer['currency_code']!=='ZAR') throw new RuntimeException('This checkout currently supports ZAR offers only.');
        if ($this->records->hasOpenEnrolment($userId,(int)$offer['course_id'])) throw new RuntimeException('This course is already in your library.');
        if ($this->records->hasPayableCourse($userId,(int)$offer['course_id'])) throw new RuntimeException('An existing order already contains this course.');
    }

    /** Captures billing and consent evidence independently of mutable profile/course data.
     * @param array<string,mixed> $checkout
     */
    public function place(CurrentUser $actor, int $cartId, string $quote, string $billingName, string $billingAddress, bool $accepted, array $checkout = []): int
    {
        self::requireCapability($actor,'COMMERCE.CHECKOUT.START');
        return $this->transactions->run(function() use ($actor,$cartId,$quote,$billingName,$billingAddress,$accepted,$checkout): int {
            $this->records->lockPurchaser($actor->id);
            $existing=$this->records->orderForCart($cartId,$actor->id);
            if ($existing!==null) return (int)$existing['id'];
            $cart=$this->cart($actor);
            if ((int)$cart['id']!==$cartId || !hash_equals((string)$cart['quote'],$quote)) throw new RuntimeException('Your cart or prices changed. Review the updated checkout.');
            if (!$accepted || trim($billingName)==='' || mb_strlen($billingName)>240 || mb_strlen($billingAddress)>2000) throw new RuntimeException('Enter your billing name and accept the purchase terms.');
            if ($cart['items']===[]) throw new RuntimeException('Your cart is empty.');
            $total=Money::strictMinorUnits(0,'ZAR'); $lines=[];
            foreach($cart['items'] as $offer) {
                $this->validateOffer($offer,$actor->id);
                $amount=Money::strictMinorUnits((int)$offer['price_minor_units'],(string)$offer['currency_code']);
                if ($amount->isFree()) throw new RuntimeException('Remove free courses from the cart and accept them directly.');
                $total=$total->plus($amount);
                $lines[]=['variant_id'=>(int)$offer['id'],'course_id'=>(int)$offer['course_id'],'course_title'=>(string)$offer['title'],'course_revision'=>(int)$offer['revision_number'],'revision_policy'=>'purchased_course_with_published_edits','access_period_seconds'=>(int)$offer['access_period_seconds'],'quantity'=>1,'unit_price_minor'=>$amount->minorUnits,'line_total_minor'=>$amount->minorUnits,'currency'=>$amount->currency,'tax_minor'=>0,'tax_rate'=>'0','discount_minor'=>0,'fulfilment_type'=>'individual_access','terms_version'=>$this->policy->termsVersion,'activation_deadline_days'=>$this->policy->activationDeadlineDays];
            }
            $now=$this->clock->now();
            $snapshot=['purchaser_user_id'=>$actor->id,'purchaser_name'=>$actor->displayName,'purchaser_email'=>$actor->primaryEmail,'billing_name'=>trim($billingName),'billing_address'=>trim($billingAddress),'items'=>$lines,'total_minor'=>$total->minorUnits,'currency'=>$total->currency,'payment_method'=>$checkout['payment_method'] ?? 'dummy','invoice_email'=>$checkout['invoice_email'] ?? false,'tax_enabled'=>false,'terms_version'=>$this->policy->termsVersion,'consent'=>['accepted_at'=>$now->format(DATE_ATOM),'session_id'=>$actor->sessionPublicId,'immediate_service'=>false],'activation_deadline_days'=>$this->policy->activationDeadlineDays];
            $id=$this->records->place($cartId,$actor->id,$total->minorUnits,$total->currency,$snapshot,$now->format(DATE_ATOM),$now->modify('+'.$this->policy->paymentDueDays.' days')->format(DATE_ATOM));
            foreach($lines as $line) $this->records->addOrderItem($id,$actor->id,$line);
            $this->records->document($id,'invoice','order:'.$id,$snapshot,$now->format(DATE_ATOM));
            $this->records->selectPaymentMethod($id, (string) ($checkout['payment_method'] ?? 'dummy'));
            if ($checkout['invoice_email'] ?? false) $this->records->enqueue('invoice:'.$id, 'invoice.email_requested', ['order_id'=>$id], $now->format(DATE_ATOM));
            $this->records->setOrderState($id,$this->transitions->apply('order','placed','await_payment'));
            $this->records->audit($id,$actor->id,'order.placed',['total_minor'=>$total->minorUnits],$now->format(DATE_ATOM));
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
