<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Infrastructure\Persistence\CompanyRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/** A company-scoped basket and checkout that creates paid credit lots through ordinary payment settlement. */
final class CompanyCreditPurchaseService
{
    public function __construct(
        private readonly CommerceRepository $records,
        private readonly CompanyRepository $companies,
        private readonly AdministrationRepository $administration,
        private readonly TransactionManager $transactions,
        private readonly CommercePolicy $policy,
        private readonly TransitionService $transitions,
        private readonly PaymentService $payments,
        private readonly ClockInterface $clock
    ) {}

    /** @return list<array<string,mixed>> */
    public function offers(int $companyId, string $search): array
    {
        $rows = $this->records->companyOffers($companyId, mb_substr(trim($search), 0, 120));
        foreach ($rows as &$row) {
            $row['price_label'] = Money::strictMinorUnits((int) $row['price_minor_units'], (string) $row['currency_code'])->format();
            $row['days'] = (int) $row['access_period_seconds'] / 86400;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function basket(CurrentUser $actor, int $companyId): array
    {
        $this->requireCompanyScope($actor, $companyId);
        $key = $_SESSION['company_credit_basket'][$actor->id][$companyId] ?? null;
        if (!is_array($key)) {
            $key = ['purchase_key' => Uuid::v4(), 'items' => [], 'request_id' => null];
            $_SESSION['company_credit_basket'][$actor->id][$companyId] = $key;
        }
        return $key;
    }

    /** @param array<string,mixed> $basket */
    private function saveBasket(CurrentUser $actor, int $companyId, array $basket): void
    {
        $_SESSION['company_credit_basket'][$actor->id][$companyId] = $basket;
    }

    public function add(CurrentUser $actor, int $companyId, int $variantId, int $quantity): void
    {
        OrderService::requireCapability($actor, 'COMPANY.CREDIT.MANAGE');
        if ($quantity < 1 || $quantity > 10000) throw new RuntimeException('Choose a quantity between 1 and 10,000.');
        $offer = $this->records->offer($variantId);
        $this->requireCompanyOffer($offer, $companyId);
        $basket = $this->basket($actor, $companyId);
        if (!isset($basket['items'][$variantId]) && count($basket['items']) >= 50) throw new RuntimeException('Place this order before adding more credit types.');
        $basket['items'][$variantId] = $quantity;
        $this->saveBasket($actor, $companyId, $basket);
    }

    public function remove(CurrentUser $actor, int $companyId, int $variantId): void
    {
        $basket = $this->basket($actor, $companyId);
        if ($basket['request_id'] !== null) {
            $requestOffer = $this->records->companyOfferForRequest((int) $basket['request_id'], $companyId);
            if ($requestOffer !== null && (int) $requestOffer['variant_id'] === $variantId) {
                throw new RuntimeException('This credit is needed to fulfil the selected course request. Cancel that request checkout first.');
            }
        }
        unset($basket['items'][$variantId]);
        $this->saveBasket($actor, $companyId, $basket);
    }

    public function cancel(CurrentUser $actor, int $companyId): void
    {
        $this->requireCompanyScope($actor, $companyId);
        unset($_SESSION['company_credit_basket'][$actor->id][$companyId]);
    }

    public function startForRequest(CurrentUser $actor, int $companyId, int $requestId): ?int
    {
        OrderService::requireCapability($actor, 'COMPANY.REQUEST.MANAGE');
        $offer = $this->records->companyOfferForRequest($requestId, $companyId);
        if ($offer === null) throw new RuntimeException('This pending request has no current exact-match paid offer.');
        $existing = $this->records->activeCompanyOrderForRequest($requestId, $companyId);
        if ($existing !== null) {
            if ((int)$existing['purchaser_user_id'] !== $actor->id) throw new RuntimeException('Another company administrator is already purchasing this credit. Ask them to complete their order.');
            return (int) $existing['id'];
        }
        $basket = $this->basket($actor, $companyId);
        if ($basket['request_id'] !== null && (int) $basket['request_id'] !== $requestId) {
            throw new RuntimeException('Finish or cancel the current request checkout first.');
        }
        $basket['request_id'] = $requestId;
        $variantId = (int) $offer['variant_id'];
        $basket['items'][$variantId] = max(1, (int) ($basket['items'][$variantId] ?? 0));
        $this->saveBasket($actor, $companyId, $basket);
        return null;
    }

    /** @return array<string,mixed> */
    public function review(CurrentUser $actor, int $companyId): array
    {
        $basket = $this->basket($actor, $companyId);
        $company = $this->companies->findById($companyId);
        if ($company === null || (string) $company['status'] !== 'active') throw new RuntimeException('The selected company is unavailable.');
        $items = [];
        $total = Money::strictMinorUnits(0, 'ZAR');
        foreach ($basket['items'] as $variantId => $quantity) {
            $offer = $this->records->offer((int) $variantId);
            $this->requireCompanyOffer($offer, $companyId);
            $quantity = (int) $quantity;
            if ($quantity < 1 || $quantity > 10000) throw new RuntimeException('A credit quantity is invalid.');
            $unit = Money::strictMinorUnits((int) $offer['price_minor_units'], 'ZAR');
            $line = $unit->times($quantity);
            $total = $total->plus($line);
            $items[] = ['variant_id'=>(int)$offer['id'],'course_id'=>(int)$offer['course_id'],
                'course_title'=>(string)$offer['title'],'access_period_seconds'=>(int)$offer['access_period_seconds'],
                'quantity'=>$quantity,'unit_price_minor'=>$unit->minorUnits,'line_total_minor'=>$line->minorUnits,
                'unit_label'=>$unit->format(),'line_label'=>$line->format(),'currency'=>'ZAR'];
        }
        if ($basket['request_id'] !== null) {
            $needed = $this->records->companyOfferForRequest((int)$basket['request_id'], $companyId);
            if ($needed === null || !isset($basket['items'][(int)$needed['variant_id']])) throw new RuntimeException('The selected course request is no longer purchasable.');
        }
        $quote = hash('sha256', json_encode([$companyId,$basket['purchase_key'],$basket['request_id'],$items], JSON_THROW_ON_ERROR));
        return ['company'=>$company,'items'=>$items,'request_id'=>$basket['request_id'],
            'purchase_key'=>$basket['purchase_key'],'quote'=>$quote,'total_minor'=>$total->minorUnits,
            'total_label'=>$total->format()];
    }

    public function place(CurrentUser $actor, int $companyId, string $key, string $quote, string $billingAddress, string $method, string $token, bool $emailInvoice, bool $accepted): int
    {
        OrderService::requireCapability($actor, 'COMPANY.CREDIT.MANAGE');
        OrderService::requireCapability($actor, 'COMMERCE.CHECKOUT.START');
        CheckoutService::validateMethod($method, $token);
        if (!$accepted || trim($billingAddress) === '' || mb_strlen($billingAddress) > 2000) {
            throw new RuntimeException('Enter the company billing address and accept the purchase terms.');
        }
        if (!Uuid::isValid($key)) throw new RuntimeException('Invalid purchase identifier.');
        [$orderId,$created] = $this->transactions->run(function () use ($actor,$companyId,$key,$quote,$billingAddress,$method,$emailInvoice): array {
            $this->records->lockPurchaser($actor->id);
            $existing = $this->records->companyOrderForKey($actor->id, $key);
            if ($existing !== null) return [(int) $existing['id'],false];
            $review = $this->review($actor, $companyId);
            if (!hash_equals((string)$review['purchase_key'], $key) || !hash_equals((string)$review['quote'], $quote)) {
                throw new RuntimeException('The selected credits or prices changed. Review the order again.');
            }
            if ($review['items'] === [] || (int)$review['total_minor'] <= 0) throw new RuntimeException('Select at least one paid credit.');
            $requestId = $review['request_id'] === null ? null : (int)$review['request_id'];
            if ($requestId !== null && $method !== 'dummy') {
                throw new RuntimeException('A request purchase needs immediate confirmed payment to enrol the learner. Use the simulated card payment.');
            }
            if ($requestId !== null && $this->records->activeCompanyOrderForRequest($requestId, $companyId) !== null) {
                throw new RuntimeException('A purchase for this request is already in progress. Open its order instead.');
            }
            $now = $this->clock->now();
            $snapshot = ['purchaser_user_id'=>$actor->id,'purchaser_name'=>$actor->displayName,'purchaser_email'=>$actor->primaryEmail,
                'company_id'=>$companyId,'company_name'=>$review['company']['name'],'billing_name'=>$review['company']['name'],
                'billing_address'=>trim($billingAddress),'request_id'=>$requestId,'items'=>$review['items'],
                'total_minor'=>$review['total_minor'],'currency'=>'ZAR','payment_method'=>$method,'invoice_email'=>$emailInvoice,
                'tax_enabled'=>false,'terms_version'=>$this->policy->termsVersion,
                'consent'=>['accepted_at'=>$now->format(DATE_ATOM),'session_id'=>$actor->sessionPublicId,'immediate_service'=>false]];
            $id = $this->records->placeCompany($actor->id,$companyId,$requestId,$key,(int)$review['total_minor'],'ZAR',$snapshot,
                $now->format(DATE_ATOM),$now->modify('+'.$this->policy->paymentDueDays.' days')->format(DATE_ATOM));
            foreach ($review['items'] as $line) $this->records->addCompanyOrderItem($id,$companyId,$line);
            $this->records->document($id,'invoice','order:'.$id,$snapshot,$now->format(DATE_ATOM));
            $this->records->selectPaymentMethod($id,$method);
            if ($emailInvoice) $this->records->enqueue('invoice:'.$id,'invoice.email_requested',['order_id'=>$id],$now->format(DATE_ATOM));
            $this->records->setOrderState($id,$this->transitions->apply('order','placed','await_payment'));
            $this->records->audit($id,$actor->id,'company_credit_order.placed',['company_id'=>$companyId,'request_id'=>$requestId,'total_minor'=>$review['total_minor']],$now->format(DATE_ATOM));
            return [$id,true];
        });
        $this->cancel($actor, $companyId);
        if ($created && $method === 'dummy') $this->payments->purchase($actor,$orderId,$key,$token);
        return $orderId;
    }

    /** @param array<string,mixed> $offer */
    private function requireCompanyOffer(array $offer, int $companyId): void
    {
        if (!(bool)$offer['is_active'] || $offer['course_status'] !== 'published' || (int)$offer['price_minor_units'] <= 0
            || (string)$offer['currency_code'] !== 'ZAR' || (int)($offer['owner_company_id'] ?? 0) === $companyId) {
            throw new RuntimeException('This paid credit offer is no longer available to this company.');
        }
    }

    private function requireCompanyScope(CurrentUser $actor, int $companyId): void
    {
        OrderService::requireCapability($actor, 'COMPANY.CREDIT.MANAGE');
        if ($companyId < 1 || (!$actor->hasPermission('PLATFORM.DASHBOARD.VIEW')
            && !$this->administration->isActiveCompanyMember($companyId, $actor->id))) {
            throw new RuntimeException('You may buy credits only for the company you administer.');
        }
    }
}
