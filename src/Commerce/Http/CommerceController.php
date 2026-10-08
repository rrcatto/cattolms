<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Commerce\Application\{BillingProfileService,OrderService,PaymentService,FulfilmentService,CartService,CheckoutService,PromotionService};
use CattoLearning\Commerce\Domain\{BillingDetails,OrderTotals,PromotionRejected};
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Support\{Csrf,Money,Pagination,Uuid};
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\{RequestStack,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Authenticated commerce pages; browser payment forms never provide payment confirmation evidence. */
final class CommerceController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly OrderService $orders, private readonly CommerceRepository $records,
        private readonly PlatformAdministrationService $administration,
        private readonly CartService $carts, private readonly CheckoutService $checkout,
        private readonly \CattoLearning\Commerce\Document\FinancialDocuments $documents,
        private readonly \CattoLearning\Commerce\Application\CommerceMaintenance $maintenance,
        private readonly \CattoLearning\Configuration\RuntimeSettings $settings,
        private readonly BillingProfileService $billing,
        private readonly PromotionService $promotions)
    { parent::__construct($auth,$view,$requests); }

    private function csrf(): void
    {
        if (!Csrf::validate($this->posted('csrf'))) throw new AccessDeniedHttpException('Invalid request token.');
    }

    #[Route('/cart',name:'commerce_cart',methods:['GET'])]
    public function cart(): Response
    {
        $cart = $this->carts->summary();
        return $this->render('commerce-cart', ['title'=>'My Cart', 'cart'=>$cart]);
    }

    #[Route('/cart/add',name:'commerce_select',methods:['POST'])]
    public function select(): Response
    {
        $this->csrf();
        $id = (int) $this->posted('variant_id');
        $offer = $this->records->offer($id);
        $return = '/courses/'.rawurlencode((string) $offer['slug']);
        return $this->handle(function() use($id,$offer,$return): void {
            if ($this->posted('action') === 'request') {
                $actor = $this->requirePermission('CATALOGUE.COURSE.REQUEST');
                $this->administration->requestCourse($actor->id,(int)$offer['course_id'],(int)$offer['access_period_seconds'],'');
                $this->flash('success','Your course request was sent to your company administrator.');
                $this->redirect('/account/courses?tab=requests');
            }
            $this->carts->change($id);
            $this->flash('success', (string) $offer['title'].' was added to My Cart.');
            $this->redirect($this->posted('action') === 'buy_now' ? '/checkout' : $return);
        }, $return);
    }

    #[Route('/cart/remove',name:'commerce_cart_remove',methods:['POST'])]
    public function remove(): Response
    {
        $this->csrf();
        return $this->handle(function(): void {
            $this->carts->change((int)$this->posted('variant_id'),true);
            $this->redirect('/cart');
        },'/cart');
    }

    /**
     * Adds a bundle by its offer. The server checks the bundle can be bought now; a course line the
     * bundle includes is taken out of the cart and the purchaser is told why.
     */
    #[Route('/cart/add-bundle',name:'commerce_select_bundle',methods:['POST'])]
    public function selectBundle(): Response
    {
        $this->csrf();
        $id = (int) $this->posted('bundle_offer_id');
        $offer = $this->records->bundleOffer($id);
        $return = '/bundles/'.rawurlencode((string) $offer['slug']);
        return $this->handle(function() use($id,$offer,$return): void {
            $this->carts->changeBundle($id);
            $this->flash('success', (string) $offer['title'].' was added to My Cart.');
            $this->redirect($this->posted('action') === 'buy_now' ? '/checkout' : $return);
        }, $return);
    }

    #[Route('/cart/remove-bundle',name:'commerce_cart_remove_bundle',methods:['POST'])]
    public function removeBundle(): Response
    {
        $this->csrf();
        return $this->handle(function(): void {
            $this->carts->changeBundle((int)$this->posted('bundle_offer_id'),true);
            $this->redirect('/cart');
        },'/cart');
    }

    /** Guests authenticate only when they elect to check out. */
    #[Route('/checkout', name:'commerce_checkout', methods:['GET'])]
    #[Route('/checkout/{step}', name:'commerce_checkout_step', requirements:['step'=>'profile|payment|review'], methods:['GET'])]
    public function checkout(): Response
    {
        if ($this->currentUser() === null) $this->redirect('/login?return=%2Fcheckout');
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START');
        $cart = $this->carts->summary();
        if ($cart['count'] === 0) $this->redirect('/cart');
        $this->checkout->begin($actor, $cart);
        $state = $this->checkout->state($actor);
        $step = (string) ($this->request()->attributes->get('step') ?? 'profile');
        if ($step !== 'profile' && !($state['profile_confirmed'] ?? false)) $this->redirect('/checkout/profile');
        if ($step === 'review' && !isset($state['payment_method'])) $this->redirect('/checkout/payment');
        $profile = $this->auth->profile($actor->id);
        $saved = $this->billing->forUser($actor->id);
        return $this->render('commerce-checkout', ['title'=>'Checkout', 'step'=>$step, 'cart'=>$cart, 'profile'=>$profile, 'checkout'=>$state,
            'billing'=>$this->billing->userForm($actor->id, $profile), 'billing_fields'=>BillingDetails::formFields(true),
            // The review shows the billing profile placement will copy into the order.
            'billing_lines'=>$saved === null ? [] : $saved->documentLines()]);
    }

    #[Route('/checkout/profile', name:'commerce_checkout_profile', methods:['POST'])]
    public function profile(): Response
    {
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START'); $this->csrf();
        return $this->handle(function () use ($actor): void {
            $fields = [];
            foreach (array_merge(['first_name','last_name','mobile_number','identification_number'], array_keys(BillingDetails::LIMITS)) as $field) $fields[$field] = $this->posted($field);
            $this->checkout->saveProfile($actor, $fields);
            $this->redirect('/checkout/payment');
        }, '/checkout/profile');
    }

    #[Route('/checkout/payment', name:'commerce_checkout_payment', methods:['POST'])]
    public function method(): Response
    {
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START'); $this->csrf();
        return $this->handle(function () use ($actor): void {
            $this->checkout->selectMethod($actor, $this->posted('payment_method'), $this->posted('method_token'), $this->posted('invoice_email') === 'yes');
            $this->redirect('/checkout/review');
        }, '/checkout/payment');
    }

    /** The browser submits the code and nothing else; the server decides whether it applies and by how much. */
    #[Route('/checkout/promo', name:'commerce_checkout_promo', methods:['POST'])]
    public function applyPromo(): Response
    {
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START'); $this->csrf();
        return $this->handle(function () use ($actor): void {
            $discount = $this->promotions->apply($actor, $this->posted('promo_code'));
            $this->flash('success', 'Promo code '.$discount->promotion->code.' applied: '.OrderTotals::negative($discount->discountMinor, $discount->currency).'.');
        }, '/checkout/review');
    }

    #[Route('/checkout/promo/remove', name:'commerce_checkout_promo_remove', methods:['POST'])]
    public function removePromo(): Response
    {
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START'); $this->csrf();
        return $this->handle(function () use ($actor): void {
            if ($this->promotions->remove($actor)) $this->flash('success', 'The promo code was removed.');
        }, '/checkout/review');
    }

    #[Route('/checkout/place', name:'commerce_place_order', methods:['POST'])]
    public function place(): Response
    {
        $actor = $this->requirePermission('COMMERCE.CHECKOUT.START'); $this->csrf();
        return $this->handle(function () use ($actor): void {
            try {
                $id = $this->checkout->place($actor, (int) $this->posted('cart_id'), $this->posted('quote'), $this->posted('accept_terms') === 'yes');
            } catch (PromotionRejected $rejected) {
                // The code stopped applying after the review was shown. It is taken off the cart so the
                // review shows the corrected total, and nothing is placed until the purchaser confirms it.
                $this->promotions->remove($actor);
                $this->flash('warning', 'Promo code '.$rejected->promoCode.' was removed from your order. '.$rejected->getMessage().' Check your new total before placing your order.');
                return;
            }
            $this->maintenance->deliverInvoices(1);
            $this->redirect($id === null ? '/account/courses' : '/account/orders/'.$id);
        }, '/checkout/review');
    }

    #[Route('/account/orders',name:'commerce_orders',methods:['GET'])]
    public function orders(): Response
    {
        $actor=$this->requirePermission('COMMERCE.ORDER.VIEW');
        $pagination=Pagination::create($this->request()->query->get('orders_page'),$this->request()->query->get('orders_page_size'),$this->records->orderCount($actor->id),25);
        $rows=$this->records->orders($actor->id,$pagination->pageSize,$pagination->offset);
        foreach($rows as &$row) $row['total_label']=Money::strictMinorUnits((int)$row['total_minor'],(string)$row['currency'])->format();
        unset($row);
        $data=['rows'=>$rows,'orders_pagination'=>PlatformAdministrationService::paginationPayload('orders',$pagination,'/account/orders','orders')];
        if ($this->isHtmxRequest()) return $this->renderFragment('partials/commerce-orders',$data);
        return $this->render('commerce-orders',$data+['title'=>'My Orders','active_nav'=>'account']);
    }

    #[Route('/account/orders/{id}',name:'commerce_order',requirements:['id'=>'[0-9]+'],methods:['GET'])]
    public function order(): Response
    {
        $actor=$this->requirePermission('COMMERCE.ORDER.VIEW');$id=(int)$this->param('id');
        $order=$this->orders->view($actor,$id);
        $this->orders->cancelDue($id);$order=$this->orders->view($actor,$id);
        $canPay=$order['state']==='awaiting_payment';
        foreach($order['payments'] as $p) if (in_array($p['state'],['pending','created','paid'],true)) $canPay=false;
        foreach($order['details']['items'] as &$line) $line['price_label']=Money::strictMinorUnits((int)$line['line_total_minor'],(string)$line['currency'])->format();
        unset($line);
        return $this->render('commerce-order',['title'=>'Order '.$id,'totals'=>OrderTotals::forSnapshot($order['details']),'active_nav'=>'account','order'=>$order,'billing_lines'=>BillingDetails::fromSnapshot((array)$order['details']['billing'])->documentLines(),'can_pay'=>$canPay,'payment_key'=>Uuid::v4(),'payment_method'=>$this->records->paymentMethod($id),'change_method'=>$this->request()->query->get('payment') === 'change','bank_details'=>$this->settings->bankDetails(),'total_label'=>Money::strictMinorUnits((int)$order['total_minor'],(string)$order['currency'])->format()]);
    }

    #[Route('/account/orders/{id}/pay',name:'commerce_pay',requirements:['id'=>'[0-9]+'],methods:['POST'])]
    public function pay(): Response
    {
        $actor=$this->requirePermission('COMMERCE.CHECKOUT.START');$this->csrf();$id=(int)$this->param('id');
        return $this->handle(function() use($actor,$id): void {
            $this->checkout->retry($actor,$id,$this->posted('payment_key'),$this->posted('payment_method'),$this->posted('method_token'));
            $this->redirect('/account/orders/'.$id);
        },'/account/orders/'.$id);
    }

    #[Route('/account/orders/{id}/documents/{document}',name:'commerce_document',requirements:['id'=>'[0-9]+','document'=>'[0-9]+'],methods:['GET'])]
    public function document(): Response
    {
        $order=$this->orders->view($this->requirePermission('COMMERCE.ORDER.VIEW'),(int)$this->param('id'));
        foreach($order['documents'] as $document) {
            if ((int)$document['id']!==(int)$this->param('document')) continue;
            $bytes = $this->documents->pdf($document);
            $disposition = $this->request()->query->get('download') === '1' ? 'attachment' : 'inline';
            return new Response($bytes, 200, ['Content-Type'=>'application/pdf', 'Content-Disposition'=>$disposition.'; filename="'.$document['number'].'.pdf"', 'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
        }
        throw $this->notFound('Document not found.');
    }
}
