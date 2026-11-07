<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Http;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Application\TransactionAttention;
use CattoLearning\Http\Controller\BaseController;
use CattoLearning\Infrastructure\Persistence\AddressKeyRepository;
use CattoLearning\Support\Address\OrderAddress;
use CattoLearning\View\ThemeRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One transaction that needs attention (owner, 2026-10-17): what happened, who it concerns, the order
 * and its ledger rows, the emails already sent, and an Email this person form. Opened from the ADMIN
 * dashboard's Transactions that need attention.
 *
 * An underpaid order's item is named by the order's number (/admin/commerce/attention/underpaid/CL-00000689, owner
 * 2026-11-06: no row id in an address); every other item by its own record's number, a ledger line, refund request or
 * refund, which keep their numbers behind the permission.
 */
final class AttentionController extends BaseController
{
    public function __construct(AuthService $auth, ThemeRenderer $view, RequestStack $requests, private readonly TransactionAttention $attention, private readonly AddressKeyRepository $keys)
    {
        parent::__construct($auth, $view, $requests);
    }

    /** The item's reference as the attention list keeps it: the order's row id for an underpaid order, named by its number. */
    private function reference(): string
    {
        if ($this->param('kind') !== 'underpaid') return $this->param('reference');
        return (string) ($this->keys->order($this->param('number')) ?? throw $this->notFound('That item does not exist.'));
    }

    #[Route('/admin/commerce/attention/{kind}/{number}', name: 'admin_attention_order_item', requirements: ['kind' => 'underpaid', 'number' => OrderAddress::REQUIREMENT], methods: ['GET'])]
    #[Route('/admin/commerce/attention/{kind}/{reference}', name: 'admin_attention_item', requirements: ['kind' => 'overpaid|cancelled_paid|refund_request|refund_to_pay', 'reference' => '[0-9]+'], methods: ['GET'])]
    public function show(): Response
    {
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        try {
            $detail = $this->attention->detail((string) $this->param('kind'), $this->reference());
        } catch (RuntimeException $missing) {
            throw $this->notFound($missing->getMessage());
        }

        return $this->render('admin-attention-item', ['title' => $detail['title'], 'active_nav' => 'admin', 'item' => $detail,
            'can_act' => $actor->hasPermission('PLATFORM.PAYMENT.MANAGE') || $actor->hasPermission('PLATFORM.REFUND.MANAGE')]);
    }

    #[Route('/admin/commerce/attention/{kind}/{number}/email', name: 'admin_attention_order_email', requirements: ['kind' => 'underpaid', 'number' => OrderAddress::REQUIREMENT], methods: ['POST'])]
    #[Route('/admin/commerce/attention/{kind}/{reference}/email', name: 'admin_attention_email', requirements: ['kind' => 'overpaid|cancelled_paid|refund_request|refund_to_pay', 'reference' => '[0-9]+'], methods: ['POST'])]
    public function email(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        $reference = $this->reference();
        $back = OrderAddress::attention($this->param('kind'), (int) $reference);

        return $this->handle(function () use ($actor, $back, $reference): void {
            $this->attention->email($actor, (string) $this->param('kind'), $reference, $this->posted('subject'), $this->posted('body'));
            $this->flash('success', 'Your email was sent and is recorded on this item.');
            $this->redirect($back);
        }, $back);
    }

    #[Route('/admin/commerce/attention/{kind}/{reference}/dismiss', name: 'admin_attention_dismiss', requirements: ['kind' => 'overpaid|cancelled_paid', 'reference' => '[0-9]+'], methods: ['POST'])]
    public function dismiss(): Response
    {
        $this->requireCsrf();
        $actor = $this->requirePermission('PLATFORM.PAYMENT.VIEW');
        $back = OrderAddress::attention($this->param('kind'), (int) $this->param('reference'));

        return $this->handle(function () use ($actor): void {
            $this->attention->dismiss($actor, (string) $this->param('kind'), (string) $this->param('reference'), $this->posted('note'));
            $this->flash('success', 'Marked as dealt with. It no longer appears on the dashboard.');
            $this->redirect('/admin/dashboard');
        }, $back);
    }
}
