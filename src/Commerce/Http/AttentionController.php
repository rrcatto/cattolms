<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\TransactionAttention;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One transaction that needs attention (owner, 2026-10-17): what happened, who it concerns, the order
 * and its ledger rows, the emails already sent, and an Email this person form. Opened from the ADMIN
 * dashboard's Transactions that need attention.
 */
final class AttentionController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly TransactionAttention $attention)
    {
        parent::__construct($auth, $view, $requests);
    }

    #[Route('/admin/commerce/attention/{kind}/{reference}', name: 'admin_attention_item', requirements: ['kind' => 'underpaid|overpaid|cancelled_paid|refund_request|refund_to_pay', 'reference' => '[0-9]+'], methods: ['GET'])]
    public function show(): Response
    {
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        try {
            $detail = $this->attention->detail((string) $this->param('kind'), (string) $this->param('reference'));
        } catch (RuntimeException $missing) {
            throw $this->notFound($missing->getMessage());
        }

        return $this->render('admin-attention-item', ['title' => $detail['title'], 'active_nav' => 'admin', 'item' => $detail,
            'can_act' => $actor->hasPermission('PLATFORM.PAYMENT.MANAGE') || $actor->hasPermission('PLATFORM.REFUND.MANAGE')]);
    }

    #[Route('/admin/commerce/attention/{kind}/{reference}/email', name: 'admin_attention_email', requirements: ['kind' => 'underpaid|overpaid|cancelled_paid|refund_request|refund_to_pay', 'reference' => '[0-9]+'], methods: ['POST'])]
    public function email(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        $back = '/admin/commerce/attention/' . $this->param('kind') . '/' . $this->param('reference');

        return $this->handle(function () use ($actor, $back): void {
            $this->attention->email($actor, (string) $this->param('kind'), (string) $this->param('reference'), $this->posted('subject'), $this->posted('body'));
            $this->flash('success', 'Your email was sent and is recorded on this item.');
            $this->redirect($back);
        }, $back);
    }

    #[Route('/admin/commerce/attention/{kind}/{reference}/dismiss', name: 'admin_attention_dismiss', requirements: ['kind' => 'overpaid|cancelled_paid', 'reference' => '[0-9]+'], methods: ['POST'])]
    public function dismiss(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        $back = '/admin/commerce/attention/' . $this->param('kind') . '/' . $this->param('reference');

        return $this->handle(function () use ($actor): void {
            $this->attention->dismiss($actor, (string) $this->param('kind'), (string) $this->param('reference'), $this->posted('note'));
            $this->flash('success', 'Marked as dealt with. It no longer appears on the dashboard.');
            $this->redirect('/admin/dashboard');
        }, $back);
    }
}
