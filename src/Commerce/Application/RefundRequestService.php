<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Infrastructure\Persistence\AdministrationRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Refunds asked for in the LMS (owner, 2026-10-17). A learner asks about a line of their own order and
 * says where the refund should go (wallet credit by default, or money back); a Company Administrator asks
 * about unused credits of the company's purchase, always as wallet credit. The Refund Policy's verdict is
 * recorded as it stands when they ask, so a request made in time is judged by its own date. ADMIN approves
 * (through RefundAdministrationService, overriding a refused rule with a written reason) or refuses with a
 * note; the requester may withdraw an open request. Every course starts on payment, so a learner's request
 * is normally refused by the rule: it is how a learner reports a faulty course.
 */
final class RefundRequestService
{
    public function __construct(
        private readonly CommerceRepository $records,
        private readonly RefundAdministrationService $refunds,
        private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock,
        private readonly AdministrationRepository $administration
    ) {}

    /** A learner's request about a line of their own order. */
    public function requestForOwnOrder(CurrentUser $actor, int $orderId, int $itemId, string $destination, string $reason): int
    {
        OrderService::requireCapability($actor, 'COMMERCE.REFUND.REQUEST');
        if (!in_array($destination, ['wallet', 'money_back'], true)) throw new RuntimeException('Choose where the refund should go: wallet credit or money back.');
        $reason = self::reason($reason);
        return $this->transactions->run(function () use ($actor, $orderId, $itemId, $destination, $reason): int {
            $this->records->lockPurchaser($actor->id);
            $order = $this->records->order($orderId, true);
            if ((int) $order['purchaser_user_id'] !== $actor->id || $order['company_id'] !== null) throw new RuntimeException('You can ask about refunds of your own orders only.');
            return $this->create($actor, $order, $itemId, 1, $destination, $reason, null);
        });
    }

    /** A Company Administrator's request about unused credits of the company's purchase. */
    public function requestForCompany(CurrentUser $actor, int $companyId, int $orderId, int $itemId, int $quantity, string $reason): int
    {
        OrderService::requireCapability($actor, 'COMPANY.CREDIT.MANAGE');
        if (!$this->administration->isActiveCompanyMember($companyId, $actor->id)) throw new RuntimeException('You can ask about refunds for the company you administer only.');
        if ($quantity < 1) throw new RuntimeException('Enter how many unused credits to refund.');
        $reason = self::reason($reason);
        return $this->transactions->run(function () use ($actor, $companyId, $orderId, $itemId, $quantity, $reason): int {
            $order = $this->records->order($orderId);
            $this->records->lockPurchaser((int) $order['purchaser_user_id']);
            $order = $this->records->order($orderId, true);
            if ((int) ($order['company_id'] ?? 0) !== $companyId) throw new RuntimeException('That order is not this company’s.');
            return $this->create($actor, $order, $itemId, $quantity, 'wallet', $reason, $companyId);
        });
    }

    public function withdraw(CurrentUser $actor, int $requestId): void
    {
        $this->transactions->run(function () use ($actor, $requestId): void {
            $request = $this->records->refundRequest($requestId, true) ?? throw new RuntimeException('Refund request not found.');
            if ((int) $request['requested_by_user_id'] !== $actor->id) throw new RuntimeException('You can withdraw your own refund requests only.');
            if ($request['status'] !== 'requested') throw new RuntimeException('This refund request has already been decided.');
            $now = $this->clock->now()->format(DATE_ATOM);
            $this->records->decideRefundRequest($requestId, 'withdrawn', $actor->id, $now, 'Withdrawn by the requester.');
            $this->records->audit((int) $request['order_id'], $actor->id, 'refund_request.withdrawn', ['refund_request_id' => $requestId], $now);
        });
    }

    public function refuse(CurrentUser $actor, int $requestId, string $note): void
    {
        OrderService::requireCapability($actor, 'PLATFORM.REFUND.MANAGE');
        $note = trim($note);
        if (mb_strlen($note) < 5 || mb_strlen($note) > 2000) throw new RuntimeException('Write why the refund is refused, in 5 to 2,000 characters.');
        $this->transactions->run(function () use ($actor, $requestId, $note): void {
            $request = $this->records->refundRequest($requestId, true) ?? throw new RuntimeException('Refund request not found.');
            if ($request['status'] !== 'requested') throw new RuntimeException('This refund request has already been decided.');
            $now = $this->clock->now()->format(DATE_ATOM);
            $this->records->decideRefundRequest($requestId, 'refused', $actor->id, $now, $note);
            $this->records->audit((int) $request['order_id'], $actor->id, 'refund_request.refused', ['refund_request_id' => $requestId, 'note' => $note], $now);
        });
    }

    /**
     * Approves a request with the refund it asked for: the line's whole unrefunded amount (or the
     * credits asked about), where the requester asked it to go. $amountMinor lets ADMIN refund less
     * than the whole line, with the reason recorded.
     */
    public function approve(CurrentUser $actor, int $requestId, string $basis, string $reason, string $overrideReason = '', ?int $amountMinor = null): int
    {
        $request = $this->records->refundRequest($requestId) ?? throw new RuntimeException('Refund request not found.');
        if ($request['status'] !== 'requested') throw new RuntimeException('This refund request has already been decided.');
        $order = $this->records->order((int) $request['order_id']);
        $company = $order['company_id'] !== null;
        $item = $this->records->orderItem((int) $request['order_item_id'], (int) $order['id']);
        $left = RefundAdministrationService::paidAmount($item) - $this->records->refundedAmount((int) $order['id'], (int) $item['id']);
        $amount = $company ? 0 : ($amountMinor ?? $left);
        return $this->refunds->approve($actor, (int) $order['id'], (int) $item['id'], Uuid::v4(), $basis, $reason, $company ? (int) $request['quantity'] : 1, $amount, $overrideReason, (string) $request['destination'], $requestId);
    }

    /**
     * The open requests for ADMIN's queue, oldest first, with what was paid, the rule's verdict and a
     * worked-out refund for each.
     *
     * @return list<array<string,mixed>>
     */
    public function queue(): array
    {
        $rows = [];
        foreach ($this->records->openRefundRequests() as $row) {
            $snapshot = CommerceRepository::decode((string) $row['item_snapshot']);
            $order = $this->records->order((int) $row['order_id']);
            $item = $this->records->orderItem((int) $row['order_item_id'], (int) $row['order_id']);
            $left = RefundAdministrationService::paidAmount($item) - $this->records->refundedAmount((int) $row['order_id'], (int) $row['order_item_id']);
            $company = $order['company_id'] !== null;
            $gross = $company ? intdiv((int) $item['amount_minor'], max(1, (int) $item['quantity'])) * (int) $row['quantity'] : $left;
            $quote = $this->refunds->quote($order, $item, $gross, (string) $row['destination']);
            $row['title'] = (string) ($snapshot['course_title'] ?? $snapshot['bundle_title'] ?? 'Order line');
            $row['order_label'] = 'CL-' . sprintf('%08d', (int) $row['order_id']);
            $row['paid_label'] = Money::strictMinorUnits(RefundAdministrationService::paidAmount($item), (string) $row['currency'])->format();
            $row['net_label'] = Money::ofMinorUnits($quote['net'] + $quote['wallet_part'], (string) $row['currency'])->format();
            $row['quote'] = $quote;
            $row['deduction_lines'] = array_map(static fn(array $d): string => $d['label'] . ': ' . Money::strictMinorUnits($d['amount_minor'], (string) $row['currency'])->format(), $quote['deductions']);
            $row['destination_label'] = $row['destination'] === 'money_back' ? 'Money back' : 'Wallet credit';
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * For the learner's order page: each line with whether a refund can be asked for, the rule's
     * verdict now and any request already made.
     *
     * @param array<string,mixed> $order a commerce_orders row
     * @return array<int,array{open:?array<string,mixed>,refusals:list<string>,can_request:bool,last:?array<string,mixed>}>
     */
    public function linesFor(array $order): array
    {
        $requests = $this->records->refundRequestsForOrder((int) $order['id']);
        $lines = [];
        foreach ($this->records->items((int) $order['id']) as $item) {
            $id = (int) $item['id'];
            $mine = array_values(array_filter($requests, static fn(array $r): bool => (int) $r['order_item_id'] === $id));
            $open = array_values(array_filter($mine, static fn(array $r): bool => $r['status'] === 'requested'))[0] ?? null;
            $settled = in_array($order['state'], ['fulfilled', 'paid', 'partially_refunded'], true);
            $left = RefundAdministrationService::paidAmount($item) - $this->records->refundedAmount((int) $order['id'], $id);
            $lines[$id] = ['open' => $open, 'refusals' => $settled ? $this->refunds->ruleRefusals($order, $item) : [], 'can_request' => $settled && $open === null && $left > 0, 'last' => $mine === [] ? null : $mine[count($mine) - 1]];
        }
        return $lines;
    }

    /** @param array<string,mixed> $order */
    private function create(CurrentUser $actor, array $order, int $itemId, int $quantity, string $destination, string $reason, ?int $companyId): int
    {
        if (!in_array($order['state'], ['fulfilled', 'paid', 'partially_refunded'], true)) throw new RuntimeException('Only a paid order can be refunded. An unpaid order is cancelled instead.');
        $item = $this->records->orderItem($itemId, (int) $order['id']);
        if ($this->records->openRefundRequestForItem($itemId) !== null) throw new RuntimeException('A refund request for this line is already waiting for a decision.');
        if (RefundAdministrationService::paidAmount($item) - $this->records->refundedAmount((int) $order['id'], $itemId) <= 0) throw new RuntimeException('This line has already been refunded.');
        $refusals = implode(' ', $this->refunds->ruleRefusals($order, $item));
        $now = $this->clock->now()->format(DATE_ATOM);
        $id = $this->records->createRefundRequest((int) $order['id'], $itemId, $actor->id, $companyId, $quantity, $destination, $reason, $refusals, $now);
        $this->records->audit((int) $order['id'], $actor->id, 'refund_request.made', ['refund_request_id' => $id, 'order_item_id' => $itemId, 'destination' => $destination, 'rule_refused' => $refusals !== ''], $now);
        return $id;
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) throw new RuntimeException('Tell us why you are asking for a refund, in 5 to 2,000 characters.');
        return $reason;
    }
}
