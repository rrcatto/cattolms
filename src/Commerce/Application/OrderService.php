<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Bundle\BundleRules;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Domain\PromotionRejected;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\LedgerKind;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Configuration\SiteMode;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use RuntimeException;

/**
 * Purchaser-scoped cart and immutable order placement; no gateway call occurs in this transaction.
 *
 * Every course starts the moment its payment is confirmed (owner, 2026-10-17): the purchaser accepts
 * that at checkout (IMMEDIATE_START), and the order records the acceptance. Wallet credit can pay part
 * or all of an order: the amount is taken from the wallet when the order is placed, as a payment of its
 * own, and shared over the lines (each line's wallet_minor) so a refund can return it.
 *
 * Only a private account buys (owner, 2026-10-18). A company account (CommerceRepository::
 * isCompanyAccount) never buys a course or bundle for itself, free or paid: its courses come from its
 * company, and the Company Administrator buys company credits through its own checkout. A company
 * person who wants a course for themselves registers a separate private account.
 */
final class OrderService
{
    /** What the purchaser accepts by ticking the start box at checkout. */
    public const IMMEDIATE_START = 'My courses start as soon as my payment is confirmed. Once a course has started I can no longer cancel it for a refund. This does not affect my rights if a course is faulty, is not as described or cannot be provided.';

    /** Why a company account cannot buy, wherever it tries. */
    public const COMPANY_ACCOUNT = 'Company accounts cannot buy courses for themselves. Your company gives you your courses. To buy a course for yourself, register a separate private account with your own email address.';

    public function __construct(
        private readonly CommerceRepository $records,
        private readonly TransactionManager $transactions,
        private readonly CommerceSettings $settings,
        private readonly TransitionService $transitions,
        private readonly ClockInterface $clock,
        private readonly PromotionService $promotions,
        private readonly FinancialDocuments $documents,
        private readonly WalletService $wallets,
        private readonly ?SiteMode $site = null,
        private readonly ?\CattoLearning\Legal\LegalDocuments $legal = null
    ) {}

    /**
     * What an order keeps of what its purchaser accepted (owner, 2026-10-18): the last-updated date of
     * the Terms and Conditions, the Refund Policy and the Privacy Policy, and the Site Owner's details at
     * that moment. Kept in the immutable snapshot beside when they accepted (consent.accepted_at).
     *
     * @return array{documents:array<string,string>,site_owner:array<string,string>}
     */
    public function acceptedDocuments(): array
    {
        return $this->legal?->acceptedDocuments() ?? ['documents' => [], 'site_owner' => []];
    }

    /**
     * What every order's snapshot keeps about where it was placed: the purchaser's account reference,
     * which the invoice quotes for EFT, and, on a demo site, that it is a simulation, so its order page,
     * documents and emails say so for good.
     *
     * @return array<string,mixed>
     */
    public function placementFacts(string $accountReference): array
    {
        return ['account_reference'=>$accountReference] + (($this->site?->isDemo() ?? false) ? ['simulation'=>true] : []);
    }

    public static function requireCapability(CurrentUser $actor, string $permission): void
    {
        if (!$actor->hasPermission($permission)) throw new AccessDeniedHttpException('You cannot perform this commerce action.');
    }

    /**
     * A payment method a purchaser may choose. The simulated card works only on a demo site
     * (SiteMode); a live site takes EFT until Stitch is built.
     */
    public function requirePaymentMethod(string $method, string $token): void
    {
        CheckoutService::validateMethod($method, $token);
        if ($method === 'dummy' && !($this->site?->isDemo() ?? true)) throw new RuntimeException('Card payments are not available yet. Pay by EFT.');
    }

    /** Whether this visitor may buy: anyone signed out (checkout asks them to sign in) and every private account. */
    public function mayBuy(?CurrentUser $viewer): bool
    {
        return $viewer === null || !$this->records->isCompanyAccount($viewer->id);
    }

    private function requirePrivateAccount(int $userId): void
    {
        if ($userId > 0 && $this->records->isCompanyAccount($userId)) throw new RuntimeException(self::COMPANY_ACCOUNT);
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
        $this->requirePrivateAccount($userId);
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

    /**
     * The offer can be bought now by this user (0 for someone not signed in): never by a company
     * account, and never a course the user already has or has on order.
     *
     * @param array<string,mixed> $offer
     */
    public function validateOffer(array $offer, int $userId): void
    {
        $this->requirePrivateAccount($userId);
        if (!(bool)$offer['is_active'] || $offer['course_status']!=='published') throw new RuntimeException('This offer is no longer available.');
        if ($offer['currency_code']!=='ZAR') throw new RuntimeException('This checkout currently supports ZAR offers only.');
        if ($this->records->hasOpenEnrolment($userId,(int)$offer['course_id'])) throw new RuntimeException('This course is already in your library.');
        if ($this->records->hasPayableCourse($userId,(int)$offer['course_id'])) throw new RuntimeException('An existing order already contains this course.');
    }

    /**
     * Captures billing and consent evidence independently of mutable profile/course data. The
     * billing details are copied into the order's snapshot as they are now; a replayed placement
     * returns the existing order and its original snapshot. The consent evidence is when and in which
     * session the purchaser accepted the Terms, the Refund Policy and that the courses start on payment
     * (consent.immediate_service). The legal pages carry no versions (owner, 2026-10-17): the time of
     * acceptance names the wording in force.
     *
     * $accepted means the purchaser accepted the Terms, the Refund Policy and that the courses start
     * on payment (CheckoutService requires both boxes). $checkout['use_wallet'] pays as much of the
     * total as the wallet holds; the rest is due by the chosen method.
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
            if (!$accepted) throw new RuntimeException('Accept the Terms and Conditions and the Refund Policy, and confirm that your courses start as soon as your payment is confirmed, before placing your order.');
            if ($cart['items']===[] && $cart['bundles']===[]) throw new RuntimeException('Your cart is empty.');
            $discount=$cart['promotion']['discount'];
            $subtotal=Money::strictMinorUnits(0,'ZAR'); $lines=[];
            foreach($cart['items'] as $offer) {
                $this->validateOffer($offer,$actor->id);
                $amount=Money::strictMinorUnits((int)$offer['price_minor_units'],(string)$offer['currency_code']);
                if ($amount->isFree()) throw new RuntimeException('Remove free courses from the cart and accept them directly.');
                $subtotal=$subtotal->plus($amount);
                $lineDiscount=$discount?->lineDiscount('course:'.(int)$offer['id']) ?? 0;
                $lines[]=['variant_id'=>(int)$offer['id'],'course_id'=>(int)$offer['course_id'],'course_title'=>(string)$offer['title'],'revision_policy'=>'purchased_course_with_published_edits','access_period_seconds'=>(int)$offer['access_period_seconds'],'quantity'=>1,'unit_price_minor'=>$amount->minorUnits,'line_total_minor'=>$amount->minorUnits,'currency'=>$amount->currency,'tax_minor'=>0,'tax_rate'=>'0','discount_minor'=>$lineDiscount,'paid_minor'=>$amount->minorUnits-$lineDiscount,'promotion_eligible'=>$discount!==null && array_key_exists('course:'.(int)$offer['id'],$discount->lineDiscounts),'fulfilment_type'=>'individual_access','start'=>'immediate'];
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
                    'revision_policy'=>'purchased_course_with_published_edits','start'=>'immediate'];
            }
            $discountMinor=$discount->discountMinor ?? 0;
            if ($discount!==null && $discount->currency!==$subtotal->currency) throw new RuntimeException('Your cart or prices changed. Review the updated checkout.');
            $total=$subtotal->minorUnits-$discountMinor;
            $now=$this->clock->now();
            $owner=WalletOwner::person($actor->id);
            $walletApplied=($checkout['use_wallet'] ?? false) ? min($this->wallets->spendable($owner,$subtotal->currency),$total) : 0;
            $method=$walletApplied===$total && $total>0 ? 'wallet' : (string) ($checkout['payment_method'] ?? 'dummy');
            if ($method==='wallet' && $walletApplied<$total) throw new RuntimeException('Your wallet no longer covers this order. Choose how to pay the rest.');
            $lines=self::shareWallet($lines,$walletApplied);
            $legal=$this->acceptedDocuments();
            $snapshot=['purchaser_user_id'=>$actor->id,'purchaser_name'=>$actor->displayName,'purchaser_email'=>$actor->primaryEmail,'billing'=>$billing->toSnapshot(),'items'=>$lines,'subtotal_minor'=>$subtotal->minorUnits,'discount_minor'=>$discountMinor,'promotion'=>$discount?->toSnapshot(),'total_minor'=>$total,'wallet_applied_minor'=>$walletApplied,'payable_minor'=>$total-$walletApplied,'currency'=>$subtotal->currency,'payment_method'=>$method,'invoice_email'=>$checkout['invoice_email'] ?? false,'tax_enabled'=>false,'consent'=>['accepted_at'=>$now->format(DATE_ATOM),'session_id'=>$actor->sessionPublicId,'immediate_service'=>true,'immediate_start_statement'=>self::IMMEDIATE_START,'documents'=>$legal['documents']],'site_owner'=>$legal['site_owner'],'start'=>'immediate']+$this->placementFacts($this->records->userAccountReference($actor->id));
            $id=$this->records->place($cartId,$actor->id,$total,$subtotal->currency,$snapshot,$now->format(DATE_ATOM),$this->settings->paymentDue()->after($now)->format(DATE_ATOM),$discount?->promotion->id,$discountMinor);
            foreach($lines as $line) $this->records->addOrderItem($id,$actor->id,$line);
            $this->documents->issueInvoice($id,$snapshot,$now->format(DATE_ATOM),$actor->id);
            $this->records->selectPaymentMethod($id, $method);
            if ($walletApplied>0) $this->payFromWallet($this->records->order($id,true),$owner,$walletApplied,$snapshot);
            if ($checkout['invoice_email'] ?? false) $this->records->enqueue('invoice:'.$id, 'invoice.email_requested', ['order_id'=>$id], $now->format(DATE_ATOM));
            $this->records->setOrderState($id,$this->transitions->apply('order','placed','await_payment'));
            $this->records->audit($id,$actor->id,'order.placed',['total_minor'=>$total]+($discount===null?[]:['promotion_id'=>$discount->promotion->id,'discount_minor'=>$discountMinor]),$now->format(DATE_ATOM));
            return $id;
        });
    }

    /**
     * Shares the wallet payment over the order's lines in proportion to what each line pays, the
     * remainder going to the lines with the largest fractions (as promotions are shared), and records
     * each share as the line's wallet_minor.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public static function shareWallet(array $lines, int $walletMinor): array
    {
        $paid=array_map(static fn(array $line): int => (int)($line['paid_minor'] ?? $line['line_total_minor'] ?? 0),$lines);
        $total=array_sum($paid);
        $shares=array_fill(0,count($lines),0);
        if ($walletMinor>0 && $total>0) {
            $fractions=[];
            foreach($paid as $i=>$amount) {
                $exact=$walletMinor*$amount;
                $shares[$i]=intdiv($exact,$total);
                $fractions[$i]=$exact%$total;
            }
            $left=$walletMinor-array_sum($shares);
            arsort($fractions);
            foreach(array_keys($fractions) as $i) { if ($left<=0) break; $shares[$i]++; $left--; }
        }
        foreach($lines as $i=>&$line) $line['wallet_minor']=$shares[$i];
        unset($line);
        return $lines;
    }

    /**
     * The wallet part of an order, taken when the order is placed: a paid payment of its own (gateway
     * wallet), the credit taken oldest first, and its receipt. Placement holds the purchaser's lock.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $snapshot
     */
    public function payFromWallet(array $order, WalletOwner $owner, int $amount, array $snapshot): string
    {
        $now=$this->clock->now()->format(DATE_ATOM);
        $paymentId=$this->records->createPayment($order,Uuid::v4(),'wallet',$now,$amount);
        $this->records->setPaymentState($paymentId,$this->transitions->apply('payment','created','submit'),null);
        $entry=$this->wallets->payForOrder($owner,(int)$order['id'],$amount,(string)$order['currency'],$paymentId);
        $reference='WALLET-'.$entry;
        $this->records->paymentEvent($paymentId,'wallet:'.$paymentId,'paid',['reference'=>$reference,'amount_minor'=>$amount,'currency'=>$order['currency']],$now);
        $this->records->setPaymentState($paymentId,$this->transitions->apply('payment','pending','confirm'),$reference);
        $this->documents->issueReceipt((int)$order['id'],$snapshot,$this->records->payment($paymentId),'wallet',$reference,$now);
        $this->records->audit((int)$order['id'],null,'payment.wallet_applied',['payment_id'=>$paymentId,'amount_minor'=>$amount],$now);
        return $paymentId;
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

    /**
     * An order cancelled before it was paid in full keeps nothing: what its wallet payment took goes
     * back to the credit it came from, and anything received by EFT or card becomes wallet credit.
     * The caller holds the purchaser's lock.
     *
     * @param array<string,mixed> $order
     */
    public function creditUnfulfilledPayments(array $order): void
    {
        $owner=WalletOwner::forOrder($order);
        $currency=(string)$order['currency'];
        $this->wallets->returnOrderPayment($owner,(int)$order['id'],$currency,'wallet_payment_released');
        foreach($this->records->payments((int)$order['id']) as $payment) {
            if ($payment['gateway']==='wallet' || $payment['state']!=='paid') continue;
            $this->wallets->credit($owner,'unfulfilled_payment_to_wallet',(int)$payment['amount_minor'],$currency,LedgerKind::methodForGateway((string)$payment['gateway']),['order_id'=>(int)$order['id'],'payment_id'=>(string)$payment['id']]);
        }
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
            $this->creditUnfulfilledPayments($row);
            return true;
        });
    }
}
