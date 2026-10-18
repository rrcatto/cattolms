<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Infrastructure\AttentionRepository;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Wallet\LedgerKind;
use CattoLearning\Commerce\Wallet\WalletRepository;
use CattoLearning\Infrastructure\Mail\MailerInterface;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Money;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Transactions that need attention, on the ADMIN dashboard (owner, 2026-10-17): money matters waiting
 * for ADMIN, oldest first.
 * - underpaid: an order paid only in part (an EFT below what was due), still waiting for the rest;
 * - overpaid: an EFT above its order, whose excess went to the payer's wallet;
 * - cancelled_paid: an order cancelled after part of it was paid, the payment now wallet credit;
 * - refund_request: a refund asked for in the LMS, waiting for a decision;
 * - refund_to_pay: a money-back refund approved and not yet recorded as paid.
 * An item leaves the list when it is resolved (paid, decided, paid out), or, for the two that need no
 * further action in the LMS, when ADMIN marks it dealt with. Each opens on its own page with an Email
 * this person form: ADMIN edits the starting text and sends it through the platform's mail, and the
 * email is recorded on the item.
 */
final class TransactionAttention
{
    public const KINDS = ['underpaid', 'overpaid', 'cancelled_paid', 'refund_request', 'refund_to_pay'];

    public function __construct(
        private readonly AttentionRepository $attention,
        private readonly CommerceRepository $records,
        private readonly WalletRepository $wallets,
        private readonly MailerInterface $mailer,
        private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock
    ) {}

    /**
     * Every open item, oldest first.
     *
     * @return list<array{kind:string,reference:string,title:string,summary:string,at:string,href:string}>
     */
    public function items(int $limit = 50): array
    {
        $items = [];
        foreach ($this->attention->partlyPaidOrders() as $row) {
            $due = (int) $row['total_minor'] - (int) $row['paid_minor'];
            if ($due <= 0) continue;
            $items[] = $this->item('underpaid', (string) $row['id'], 'Order ' . self::order((int) $row['id']) . ' is underpaid',
                Money::strictMinorUnits((int) $row['external_minor'], (string) $row['currency'])->format() . ' received; ' . Money::strictMinorUnits($due, (string) $row['currency'])->format() . ' still due by ' . self::date((string) $row['payment_due_at']) . '.', (string) $row['placed_at']);
        }
        foreach (['overpaid' => 'overpayment_to_wallet', 'cancelled_paid' => 'unfulfilled_payment_to_wallet'] as $kind => $ledgerKind) {
            foreach ($this->attention->undismissedEntries($ledgerKind, $kind) as $row) {
                $amount = Money::strictMinorUnits((int) $row['amount_minor'], (string) $row['currency'])->format();
                $items[] = $kind === 'overpaid'
                    ? $this->item($kind, (string) $row['id'], 'Order ' . self::order((int) $row['order_id']) . ' was overpaid', $amount . ' more than was due went to the payer’s wallet.', (string) $row['occurred_at'])
                    : $this->item($kind, (string) $row['id'], 'Order ' . self::order((int) $row['order_id']) . ' was cancelled after a payment', $amount . ' paid towards it went to the payer’s wallet.', (string) $row['occurred_at']);
            }
        }
        foreach ($this->attention->openRefundRequests() as $row) {
            $items[] = $this->item('refund_request', (string) $row['id'], (string) $row['display_name'] . ' asked for a refund', 'Order ' . self::order((int) $row['order_id']) . ', as ' . ($row['destination'] === 'money_back' ? 'money back' : 'wallet credit') . '.', (string) $row['requested_at']);
        }
        foreach ($this->attention->unpaidMoneyBackRefunds() as $row) {
            $items[] = $this->item('refund_to_pay', (string) $row['id'], 'Refund to pay for order ' . self::order((int) $row['order_id']), Money::strictMinorUnits((int) $row['net_minor'], (string) $row['currency'])->format() . ' by ' . ($row['method'] === 'stitch' ? 'the Stitch dashboard' : 'EFT') . ', due by ' . self::date((string) $row['payout_due_at']) . '.', (string) $row['payout_due_at']);
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));

        return array_slice($items, 0, $limit);
    }

    /**
     * One item for its page: what happened, who it concerns, the order, the ledger rows and the emails
     * already sent, with a starting subject and text for a new email.
     *
     * @return array<string,mixed>
     */
    public function detail(string $kind, string $reference): array
    {
        if (!in_array($kind, self::KINDS, true) || preg_match('/^\d{1,18}$/', $reference) !== 1) throw new RuntimeException('That item does not exist.');
        $item = array_values(array_filter($this->items(500), static fn(array $i): bool => $i['kind'] === $kind && $i['reference'] === $reference))[0] ?? null;
        $orderId = $this->attention->orderFor($kind, (int) $reference) ?? throw new RuntimeException('That item does not exist.');
        $order = $this->records->order($orderId);
        $snapshot = CommerceRepository::decode((string) $order['snapshot']);
        $person = ['name' => (string) ($snapshot['purchaser_name'] ?? ''), 'email' => (string) ($snapshot['purchaser_email'] ?? '')];
        $accountReference = AccountReference::format($order['company_id'] !== null ? $this->records->companyAccountReference((int) $order['company_id']) : $this->records->userAccountReference((int) $order['purchaser_user_id']));
        $ledger = array_map(static fn(array $row): array => ['label' => LedgerKind::label((string) $row['kind']), 'amount' => Money::strictMinorUnits((int) $row['amount_minor'], (string) $row['currency'])->format(), 'at' => (string) $row['occurred_at'], 'memo' => (string) $row['memo']], $this->wallets->entriesForOrder($orderId));
        $title = $item['title'] ?? 'Order ' . self::order($orderId);

        return [
            'kind' => $kind, 'reference' => $reference, 'open' => $item !== null, 'title' => $title, 'summary' => $item['summary'] ?? 'This item has been resolved.',
            'order_id' => $orderId, 'order_label' => self::order($orderId), 'company_name' => (string) ($snapshot['company_name'] ?? ''),
            'person' => $person, 'account_reference' => $accountReference, 'ledger' => $ledger,
            'emails' => $this->attention->emails($kind, $reference),
            'subject' => 'About your order ' . self::order($orderId),
            'body' => self::startingText($kind, $person['name'], self::order($orderId), $item['summary'] ?? '', $accountReference),
            'can_dismiss' => in_array($kind, ['overpaid', 'cancelled_paid'], true) && $item !== null,
        ];
    }

    /** Sends ADMIN's email about an item to the purchaser and records it with the item. */
    public function email(CurrentUser $actor, string $kind, string $reference, string $subject, string $body): void
    {
        $this->requireManage($actor);
        $subject = trim($subject);
        $body = trim($body);
        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 200) throw new RuntimeException('Write a subject of 3 to 200 characters.');
        if (mb_strlen($body) < 5 || mb_strlen($body) > 10000) throw new RuntimeException('Write the email, in 5 to 10,000 characters.');
        $detail = $this->detail($kind, $reference);
        $recipient = (string) $detail['person']['email'];
        if ($recipient === '') throw new RuntimeException('This order has no email address to write to.');
        $this->mailer->sendAdministratorMessage($recipient, $subject, $body);
        $this->attention->recordEmail($kind, $reference, $recipient, $subject, $body, $actor->id, $this->clock->now()->format(DATE_ATOM));
        $this->records->audit((int) $detail['order_id'], $actor->id, 'attention.email_sent', ['kind' => $kind, 'reference' => $reference], $this->clock->now()->format(DATE_ATOM));
    }

    /** Marks an overpayment or a cancelled order's payment as dealt with: it leaves the list. */
    public function dismiss(CurrentUser $actor, string $kind, string $reference, string $note): void
    {
        $this->requireManage($actor);
        if (!in_array($kind, ['overpaid', 'cancelled_paid'], true)) throw new RuntimeException('This item leaves the list when it is resolved.');
        $detail = $this->detail($kind, $reference);
        $this->transactions->run(function () use ($actor, $kind, $reference, $note, $detail): void {
            $this->attention->dismiss($kind, $reference, $actor->id, $this->clock->now()->format(DATE_ATOM), mb_substr(trim($note), 0, 2000));
            $this->records->audit((int) $detail['order_id'], $actor->id, 'attention.dismissed', ['kind' => $kind, 'reference' => $reference], $this->clock->now()->format(DATE_ATOM));
        });
    }

    /** @return array{kind:string,reference:string,title:string,summary:string,at:string,href:string} */
    private function item(string $kind, string $reference, string $title, string $summary, string $at): array
    {
        return ['kind' => $kind, 'reference' => $reference, 'title' => $title, 'summary' => $summary, 'at' => $at, 'href' => '/admin/commerce/attention/' . $kind . '/' . $reference];
    }

    private static function startingText(string $kind, string $name, string $order, string $summary, string $reference): string
    {
        $greeting = 'Hello' . ($name !== '' ? ' ' . $name : '') . ',';
        $text = match ($kind) {
            'underpaid' => 'Thank you for your EFT payment for order ' . $order . '. ' . $summary . ' Please pay the rest, quoting your account reference ' . $reference . ', so that we can start your course.',
            'overpaid' => 'Thank you for your EFT payment for order ' . $order . '. ' . str_replace('the payer’s wallet', 'your wallet', $summary) . ' You can spend that credit on any course.',
            'cancelled_paid' => 'Your order ' . $order . ' was cancelled because it was not paid in full in time. ' . str_replace('the payer’s wallet', 'your wallet', $summary) . ' You can spend that credit on any course.',
            'refund_request' => 'Thank you for your refund request for order ' . $order . '.',
            'refund_to_pay' => 'Your refund for order ' . $order . ' has been approved and will be paid within 7 days of your request.',
            default => '',
        };

        return $greeting . "\n\n" . $text . "\n\nKind regards,\nCatto Learning";
    }

    private function requireManage(CurrentUser $actor): void
    {
        if (!$actor->hasPermission('PLATFORM.PAYMENT.MANAGE') && !$actor->hasPermission('PLATFORM.REFUND.MANAGE')) throw new RuntimeException('You cannot act on transactions.');
    }

    private static function order(int $id): string
    {
        return 'CL-' . sprintf('%08d', $id);
    }

    private static function date(string $at): string
    {
        return (new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j M Y');
    }
}
