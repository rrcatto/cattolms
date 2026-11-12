<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Support\Address\OrderAddress;
use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\CompanyCreditPurchaseService;
use CattoLearning\Commerce\Application\CommerceMaintenance;
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Support\Money;
use CattoLearning\Company\SelectedCompanyContext;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\View\ThemeRenderer;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Native company-credit basket and checkout; the selected company always comes from the trusted session context. */
final class CompanyCreditController extends BaseController
{
    public function __construct(
        AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly SelectedCompanyContext $companyContext,
        private readonly CompanyCreditPurchaseService $purchases,
        private readonly CommerceMaintenance $maintenance,
        private readonly WalletService $wallets,
        private readonly CommerceSettings $settings,
        private readonly \CattoLearning\Commerce\Settings\PaymentMethods $paymentMethods
    ) { parent::__construct($auth,$view,$requests); }

    private function companyId(CurrentUser $actor): int
    {
        $context = $this->companyContext->resolve($actor);
        $id = (int)$context['company_id'];
        if ($id < 1) throw new \RuntimeException('Select a company first.');
        return $id;
    }

    #[Route('/company/credits/buy', name: 'company_credit_buy', methods: ['GET'])]
    public function buy(): Response
    {
        $actor = $this->requirePermission('COMPANY.CREDIT.MANAGE');
        $companyId = $this->companyId($actor);
        $search = trim((string)$this->request()->query->get('search',''));
        $basketError = null;
        try {
            $basket = $this->purchases->review($actor,$companyId);
        } catch (\RuntimeException $exception) {
            $basketError = $exception->getMessage();
            $basket = ['company'=>$this->companyContext->resolve($actor)['company'],'items'=>[],'request_id'=>null,'total_label'=>''];
        }
        return $this->render('company-credit-buy', [
            'title'=>'Buy company credits','active_nav'=>'company',
            'offers'=>$this->purchases->offers($companyId,$search),
            // Bundles as bundle credits, one credit one person (owner, 2026-11-11), with the rule said where they are bought.
            'bundle_offers'=>$this->purchases->bundleOffers(),'bundle_rule'=>\CattoLearning\Commerce\Application\BundleCredits::RULE,
            'chosen_bundle'=>(int)$this->request()->query->get('bundle', 0),
            'search'=>$search,'basket'=>$basket,'basket_error'=>$basketError,
        ]);
    }

    #[Route('/company/credits/buy/add', name: 'company_credit_buy_add', methods: ['POST'])]
    public function add(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): void {
            $this->purchases->add($actor,$this->companyId($actor),(int)($_POST['variant_id'] ?? 0),(int)($_POST['quantity'] ?? 0));
            $this->flash('success','Credits added to the company purchase.');
            $this->redirect('/company/credits/buy');
        },'/company/credits/buy');
    }

    #[Route('/company/credits/buy/add-bundle', name: 'company_credit_buy_add_bundle', methods: ['POST'])]
    public function addBundle(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): void {
            $this->purchases->addBundle($actor,$this->companyId($actor),(int)($_POST['bundle_offer_id'] ?? 0),(int)($_POST['quantity'] ?? 0));
            $this->flash('success','Bundle credits added to the company purchase. ' . \CattoLearning\Commerce\Application\BundleCredits::RULE);
            $this->redirect('/company/credits/buy');
        },'/company/credits/buy');
    }

    #[Route('/company/credits/buy/remove-bundle', name: 'company_credit_buy_remove_bundle', methods: ['POST'])]
    public function removeBundle(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): void {
            $this->purchases->removeBundle($actor,$this->companyId($actor),(int)($_POST['bundle_offer_id'] ?? 0));
            $this->redirect('/company/credits/buy');
        },'/company/credits/buy');
    }

    #[Route('/company/credits/buy/remove', name: 'company_credit_buy_remove', methods: ['POST'])]
    public function remove(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): void {
            $this->purchases->remove($actor,$this->companyId($actor),(int)($_POST['variant_id'] ?? 0));
            $this->redirect('/company/credits/buy');
        },'/company/credits/buy');
    }

    #[Route('/company/credits/buy/cancel', name: 'company_credit_buy_cancel', methods: ['POST'])]
    public function cancel(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        $this->purchases->cancel($actor,$this->companyId($actor));
        $this->redirect('/company/credits');
    }

    #[Route('/company/credits/checkout', name: 'company_credit_checkout', methods: ['GET'])]
    public function checkout(): Response
    {
        $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): Response {
            $companyId=$this->companyId($actor);
            $basket=$this->purchases->startCheckout($actor,$companyId);
            if ($basket['items'] === []) $this->redirect('/company/credits/buy');
            // The company wallet pays as much as it holds when the administrator chooses it (owner, 2026-10-17),
            // for a learner's request too (owner, 2026-10-19).
            $balance=$this->wallets->spendable(WalletOwner::company($companyId),'ZAR');
            return $this->render('company-credit-checkout',['title'=>'Company credit checkout','active_nav'=>'company','basket'=>$basket,
                'billing'=>$this->purchases->billingForm($actor,$companyId),'billing_fields'=>BillingDetails::formFields(false),
                'wallet_balance_minor'=>$balance,'wallet_balance_label'=>Money::ofMinorUnits($balance,'ZAR')->format(),
                'payment_due'=>$this->settings->paymentDue()->words(),'refund_window'=>$this->settings->refundWindow()->words(),
                'payment_options'=>$this->paymentMethods->checkoutOptions(),
                'bundle_rule'=>\CattoLearning\Commerce\Application\BundleCredits::RULE,'bundle_accept'=>\CattoLearning\Commerce\Application\BundleCredits::ACCEPT]);
        },'/company/credits/buy');
    }

    #[Route('/company/credits/checkout', name: 'company_credit_place', methods: ['POST'])]
    public function place(): Response
    {
        $this->requireCsrf(); $actor=$this->requirePermission('COMPANY.CREDIT.MANAGE');
        return $this->handle(function() use($actor): void {
            $id=$this->purchases->place($actor,$this->companyId($actor),(string)($_POST['purchase_key'] ?? ''),
                (string)($_POST['quote'] ?? ''),BillingDetails::forCompany($_POST),
                (string)($_POST['payment_method'] ?? ''),(string)($_POST['method_token'] ?? ''),
                ($_POST['invoice_email'] ?? '') === 'yes',($_POST['accept_terms'] ?? '') === 'yes',($_POST['use_wallet'] ?? '') === 'yes',
                ($_POST['accept_bundle_use'] ?? '') === 'yes');
            $this->maintenance->deliverInvoices(1);
            $this->maintenance->deliverCompanyCourseNotices(2);
            $this->redirect(OrderAddress::buyer($id));
        },'/company/credits/checkout');
    }
}
