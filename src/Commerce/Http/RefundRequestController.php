<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\RefundRequestService;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Support\AccountReference;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration > Credits & Orders > Refund Requests (owner, 2026-10-17): the refunds learners and
 * Company Administrators asked for in the LMS, oldest first, each with the Refund Policy's verdict when
 * it was asked, what was paid and what the refund would come to. ADMIN approves (overriding a refused
 * rule with a written reason) or refuses with a note the requester sees.
 */
final class RefundRequestController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly RefundRequestService $refundRequests)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/commerce/refund-requests', name: 'admin_refund_requests', methods: ['GET'])]
    public function queue(): Response
    {
        $actor = $this->requirePermission('PLATFORM.REFUND.VIEW');
        $rows = $this->refundRequests->queue();
        foreach ($rows as &$row) {
            $row['reference_label'] = AccountReference::format((string) $row['requester_reference']);
            // Who asked, as a person (owner, 2026-10-21).
            $row['requester'] = \CattoLearning\Commerce\Application\MoneyRecords::person((int) $row['requested_by_user_id'], (string) $row['requester_name'], (string) $row['requester_reference'], (string) ($row['requester_email'] ?? ''));
        }
        unset($row);

        return $this->render('admin-refund-requests', ['title' => 'Refund Requests', 'active_nav' => 'admin', 'requests' => $rows, 'can_decide' => $actor->hasPermission('PLATFORM.REFUND.MANAGE')]);
    }

    #[Route('/admin/commerce/refund-requests/{id}/approve', name: 'admin_refund_request_approve', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function approve(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.REFUND.MANAGE');
        return $this->handle(function () use ($actor): void {
            $amount = $this->posted('amount') === '' ? null : self::amount($this->posted('amount'));
            $this->refundRequests->approve($actor, (int) $this->param('id'), $this->posted('basis'), $this->posted('reason'), $this->posted('override_reason'), $amount);
            $this->flash('success', 'The refund was approved and its credit note issued.');
            $this->redirect('/admin/commerce/refund-requests');
        }, '/admin/commerce/refund-requests');
    }

    #[Route('/admin/commerce/refund-requests/{id}/refuse', name: 'admin_refund_request_refuse', requirements: ['id' => '[0-9]+'], methods: ['POST'])]
    public function refuse(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.REFUND.MANAGE');
        return $this->handle(function () use ($actor): void {
            $this->refundRequests->refuse($actor, (int) $this->param('id'), $this->posted('note'));
            $this->flash('success', 'The refund request was refused. The requester sees your note on the order.');
            $this->redirect('/admin/commerce/refund-requests');
        }, '/admin/commerce/refund-requests');
    }

    private static function amount(string $value): int
    {
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || strlen($value) > 16) throw new RuntimeException('Enter an amount in rands and cents, such as 123.45.');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad($fraction, 2, '0');
    }
}
