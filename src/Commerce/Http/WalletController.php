<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\MoneyRecords;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletRepository;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Company\SelectedCompanyContext;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Money;
use CattoLearning\Support\Pagination;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wallet pages (owner, 2026-10-17): My Wallet (Account > Courses), the company's Wallet (Company), and
 * for ADMIN every wallet with its statement and corrections. A wallet shows its balance, the credit that
 * expires soon and a statement of every movement with the balance after it. Wallet credit is spent on
 * courses and company credits and is never paid out as cash; it earns no interest.
 */
final class WalletController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests,
        private readonly WalletService $wallets, private readonly WalletRepository $walletRecords,
        private readonly CommerceSettings $settings, private readonly SelectedCompanyContext $companyContext,
        private readonly CommerceRepository $records, private readonly TransactionManager $transactions)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/account/wallet', name: 'account_wallet', methods: ['GET'])]
    public function mine(): Response
    {
        $actor = $this->requirePermission('COMMERCE.WALLET.VIEW');
        $owner = WalletOwner::person($actor->id);

        return $this->wallet('commerce-wallet', $owner, '/account/wallet', [
            'title' => 'My Wallet', 'active_nav' => 'account',
            'owner_name' => $actor->displayName, 'reference' => $this->records->userAccountReference($actor->id),
        ]);
    }

    #[Route('/company/wallet', name: 'company_wallet', methods: ['GET'])]
    public function company(): Response
    {
        $actor = $this->requirePermission('COMPANY.WALLET.VIEW');
        $context = $this->companyContext->resolve($actor);
        $companyId = (int) $context['company_id'];
        if ($companyId < 1) throw new RuntimeException('Select a company first.');

        return $this->wallet('company-wallet', WalletOwner::company($companyId), '/company/wallet', [
            'title' => 'Wallet', 'active_nav' => 'company',
            'owner_name' => (string) ($context['company']['name'] ?? 'Company'), 'reference' => $this->records->companyAccountReference($companyId),
        ]);
    }

    #[Route('/admin/wallets', name: 'admin_wallets', methods: ['GET'])]
    public function all(): Response
    {
        $this->requirePermission('PLATFORM.WALLET.VIEW');
        $search = mb_substr(trim((string) $this->request()->query->get('wallets_q', '')), 0, 120);
        $pagination = Pagination::create($this->request()->query->get('wallets_page'), $this->request()->query->get('wallets_page_size'), $this->walletRecords->walletCount($search), 50);
        $rows = $this->walletRecords->wallets($search, $pagination->pageSize, $pagination->offset);
        foreach ($rows as &$row) {
            $row['balance_label'] = Money::ofMinorUnits(max(0, (int) $row['balance_minor']), (string) $row['currency'])->format();
            $row['reference_label'] = AccountReference::format((string) $row['account_reference']);
            $row['href'] = '/admin/wallets/' . ($row['owner_company_id'] !== null ? 'company/' . (int) $row['owner_company_id'] : 'person/' . (int) $row['owner_user_id']);
            // A person's wallet names its owner as a person (owner, 2026-10-21); a company's stays its name.
            $row['owner_person'] = $row['owner_user_id'] !== null ? MoneyRecords::person((int) $row['owner_user_id'], (string) $row['owner_name'], (string) $row['account_reference'], (string) ($row['owner_email'] ?? '')) : null;
        }
        unset($row);

        return $this->render('admin-wallets', ['title' => 'Wallets', 'active_nav' => 'admin', 'rows' => $rows, 'wallets_search' => $search,
            'wallets_pagination' => PlatformAdministrationService::paginationPayload('wallets', $pagination, '/admin/wallets', 'wallets', $search === '' ? [] : ['wallets_q' => $search])]);
    }

    #[Route('/admin/wallets/{type}/{id}', name: 'admin_wallet', requirements: ['type' => 'person|company', 'id' => '[0-9]+'], methods: ['GET'])]
    public function one(): Response
    {
        $actor = $this->requirePermission('PLATFORM.WALLET.VIEW');
        $owner = $this->ownerFromRoute();
        $base = '/admin/wallets/' . $this->param('type') . '/' . $this->param('id');

        return $this->wallet('admin-wallet', $owner, $base, [
            'title' => 'Wallet', 'active_nav' => 'admin',
            'owner_name' => $owner->isCompany() ? $this->records->companyName((int) $owner->companyId) : $this->records->userName((int) $owner->userId),
            'reference' => $owner->isCompany() ? $this->records->companyAccountReference((int) $owner->companyId) : $this->records->userAccountReference((int) $owner->userId),
            'owner_person' => $owner->isCompany() ? null : MoneyRecords::person((int) $owner->userId, $this->records->userName((int) $owner->userId), $this->records->userAccountReference((int) $owner->userId), $this->records->userEmail((int) $owner->userId)),
            'can_correct' => $actor->hasPermission('PLATFORM.WALLET.MANAGE'), 'correct_href' => $base . '/correct',
        ]);
    }

    #[Route('/admin/wallets/{type}/{id}/correct', name: 'admin_wallet_correct', requirements: ['type' => 'person|company', 'id' => '[0-9]+'], methods: ['POST'])]
    public function correct(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.WALLET.MANAGE');
        $owner = $this->ownerFromRoute();
        $back = '/admin/wallets/' . $this->param('type') . '/' . $this->param('id');

        return $this->handle(function () use ($actor, $owner, $back): void {
            $value = trim($this->posted('amount'));
            if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) throw new RuntimeException('Enter the amount to add, or a negative amount to take away, such as 150.00 or -150.00.');
            $negative = str_starts_with($value, '-');
            [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $minor = ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
            $this->transactions->run(fn() => $this->wallets->correct($owner, $negative ? -$minor : $minor, 'ZAR', $this->posted('reason'), $actor->id));
            $this->flash('success', 'The correction is recorded.');
            $this->redirect($back);
        }, $back);
    }

    /** @param array<string,mixed> $page */
    private function wallet(string $template, WalletOwner $owner, string $base, array $page): Response
    {
        $query = $this->request()->query;
        $total = $this->wallets->statement($owner, 1, 0)['total'];
        $pagination = Pagination::create($query->get('statement_page'), $query->get('statement_page_size'), $total, 50);
        $statement = $this->wallets->statement($owner, $pagination->pageSize, $pagination->offset);

        return $this->render($template, $page + [
            'reference_label' => AccountReference::format((string) $page['reference']),
            'balance_label' => Money::ofMinorUnits($this->wallets->balance($owner))->format(),
            'expiring' => $this->wallets->expiringSoon($owner),
            'expiry_words' => $this->settings->walletExpiry()->words(),
            'reminder_words' => $this->settings->walletReminder()->words(),
            'is_company' => $owner->isCompany(),
            'rows' => $statement['rows'],
            'statement_pagination' => PlatformAdministrationService::paginationPayload('statement', $pagination, $base, 'wallet movements'),
        ]);
    }

    private function ownerFromRoute(): WalletOwner
    {
        $id = (int) $this->param('id');

        return $this->param('type') === 'company' ? WalletOwner::company($id) : WalletOwner::person($id);
    }
}
