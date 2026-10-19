<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Settings\CommerceSettings;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Configuration\RuntimeSettings;
use CattoLearning\Support\AccessPeriod;
use CattoLearning\Support\AccountReference;
use CattoLearning\Support\Env;
use CattoLearning\Support\Money;
use CattoLearning\Infrastructure\Mail\MailerInterface;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies elapsed commerce deadlines and retries requested invoice delivery in bounded batches. Writes
 * off wallet credit whose expiry has passed and emails each wallet's reminder before its credit expires
 * (owner, 2026-10-17), once per credit. Sends every buyer's order confirmation, with the order's
 * receipts attached, and a learner's email naming the courses an order started; and to an EFT buyer how
 * to pay, what arrived after a part payment and the cancellation of an order left unpaid (owner, 2026-10-19).
 */
final class CommerceMaintenance
{
    public function __construct(private readonly CommerceRepository $records, private readonly OrderService $orders,
        private readonly AccessService $access, private readonly FinancialDocuments $documents,
        private readonly MailerInterface $mailer, private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock, private readonly WalletService $wallets,
        private readonly CommerceSettings $settings, private readonly RuntimeSettings $runtime) {}

    public function run(): void
    {
        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($this->records->dueOrders($now, 200) as $id) $this->orders->cancelDue($id);
        foreach ($this->records->dueEntitlements($now, 200) as $id) $this->access->reconcile($id);
        $this->deliverInvoices();
        $this->deliverBuyerEmails();
        $this->deliverCompanyCourseNotices();
        $this->transactions->run(fn(): int => $this->wallets->expireDue(200));
        $this->remindWallets();
    }

    /** One email per wallet whose credit expires within the reminder period; each credit is reminded once. */
    public function remindWallets(int $limit = 20): void
    {
        $base = rtrim(Env::string('APP_URL', ''), '/');
        foreach ($this->wallets->remindersDue($limit) as $wallet) {
            $owner = $wallet['owner_company_id'] !== null ? WalletOwner::company($wallet['owner_company_id']) : WalletOwner::person((int) $wallet['owner_user_id']);
            $lines = array_map(static fn(array $lot): array => ['amount' => Money::strictMinorUnits($lot['remaining'], $wallet['currency'])->format(),
                'expires' => (new \DateTimeImmutable($lot['expires_at']))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j F Y')], $wallet['lots']);
            $url = $base . ($owner->isCompany() ? '/company/wallet' : '/account/wallet');
            try {
                foreach ($this->wallets->recipients($owner) as $recipient) $this->mailer->sendWalletExpiryReminder($recipient['email'], $recipient['name'], $lines, $url);
            } catch (\Throwable) {
                continue;
            }
            $this->transactions->run(function () use ($wallet): void {
                foreach ($wallet['lots'] as $lot) $this->wallets->markReminded($lot['id']);
            });
        }
    }

    public function deliverInvoices(int $limit = 20): void
    {
        for ($i=0; $i<$limit; $i++) {
            $found = $this->transactions->run(function (): bool {
                $event = $this->records->nextInvoiceEmail();
                if ($event === null) return false;
                try {
                    $payload = CommerceRepository::decode((string) $event['payload']);
                    foreach ($this->records->documents((int) $payload['order_id']) as $document) {
                        if ($document['kind'] !== 'invoice') continue;
                        $snapshot = CommerceRepository::decode((string) $document['snapshot']);
                        $this->mailer->sendInvoice((string) $snapshot['purchaser_email'], (string) $document['number'], $this->documents->pdf($document));
                    }
                    $this->records->emailDelivered((int) $event['id'], null);
                } catch (\Throwable) {
                    $this->records->emailDelivered((int) $event['id'], 'Invoice delivery failed; retry scheduled.');
                }
                return true;
            });
            if (!$found) return;
        }
    }

    /**
     * The buyer emails queued for an order, oldest first: how to pay by EFT and what arrived after a part
     * payment (OrderService, PaymentService), the cancellation of an unpaid order (OrderService::cancelDue),
     * and the order confirmation and course-started emails (FulfilmentService). An email whose moment has
     * passed by the time it is sent (the order was paid or cancelled first) is dropped, not sent.
     */
    public function deliverBuyerEmails(int $limit = 20): void
    {
        $base = rtrim(Env::string('APP_URL', ''), '/');
        for ($i=0; $i<$limit; $i++) {
            $found = $this->transactions->run(function () use ($base): bool {
                $event = $this->records->nextBuyerEmail();
                if ($event === null) return false;
                try {
                    $payload = CommerceRepository::decode((string) $event['payload']);
                    $orderId = (int) ($payload['order_id'] ?? 0);
                    $order = $this->records->order($orderId);
                    $snapshot = CommerceRepository::decode((string) $order['snapshot']);
                    $email = (string) ($snapshot['purchaser_email'] ?? '');
                    $name = (string) ($snapshot['purchaser_name'] ?? '');
                    if ($event['event'] === 'order.payment_instructions' || $event['event'] === 'order.part_paid') {
                        $part = $event['event'] === 'order.part_paid';
                        if ($order['state'] === 'awaiting_payment' && $this->records->paymentMethod($orderId) === 'eft' && $this->records->dueAmount($orderId) > 0) {
                            $document = $part ? $this->records->documentForSource('payment:' . (string) ($payload['payment_id'] ?? '')) : $this->invoice($orderId);
                            $this->mailer->sendPaymentInstructions($email, $this->instructions($order, $snapshot, $base, $part ? (string) $payload['payment_id'] : null),
                                $this->runtime->payableBankDetails(), $document === null ? [] : [(string) $document['number'] => $this->documents->pdf($document)]);
                        }
                    } elseif ($event['event'] === 'order.cancelled_unpaid') {
                        if ($order['state'] === 'cancelled') $this->mailer->sendOrderCancelled($email, $this->cancellation($order, $snapshot, $base));
                    } elseif ($event['event'] === 'order.confirmed') {
                        $this->mailer->sendOrderConfirmation($email, $this->confirmation($order, $snapshot, $base), $this->receipts($orderId));
                    } else {
                        $courses = array_map(fn(array $course): array => ['title' => (string) $course['title'], 'until' => self::day((string) $course['expires_at']),
                            'url' => $base . '/learn/' . rawurlencode((string) $course['slug'])], $this->records->coursesStartedByOrder($orderId));
                        if ($courses !== []) $this->mailer->sendCoursesStarted($email, $name, $courses);
                    }
                    $this->records->emailDelivered((int) $event['id'], null);
                } catch (\Throwable) {
                    $this->records->emailDelivered((int) $event['id'], 'Buyer email delivery failed; retry scheduled.');
                }
                return true;
            });
            if (!$found) return;
        }
    }

    /**
     * What the order confirmation says: the order, what was bought, the total and how it was paid.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $snapshot
     * @return array{number:string,name:string,account_reference:string,placed:string,lines:list<array{title:string,detail:string,amount:string}>,total:string,paid:string,url:string}
     */
    private function confirmation(array $order, array $snapshot, string $base): array
    {
        $currency = (string) $order['currency'];
        $lines = $this->lines($order);
        $parts = [];
        $last = '';
        foreach ($this->records->payments((int) $order['id']) as $payment) {
            if (!in_array((string) $payment['state'], ['paid', 'partially_refunded', 'refunded'], true)) continue;
            $how = match ((string) $payment['gateway']) { 'manual_bank' => 'by EFT', 'wallet' => 'from your wallet', default => 'by card' };
            $parts[] = Money::ofMinorUnits((int) $payment['amount_minor'], $currency)->format() . ' ' . $how;
            $last = (string) $payment['created_at'];
        }
        $paid = (int) $order['total_minor'] === 0 ? 'Nothing was left to pay after the promotion.'
            : 'Paid in full' . ($last !== '' ? ' on ' . self::day($last) : '') . ($parts !== [] ? ': ' . implode(' and ', $parts) : '') . '.';

        return [
            'number' => MoneyRecords::orderNumber((int) $order['id']), 'name' => (string) ($snapshot['purchaser_name'] ?? ''),
            'account_reference' => AccountReference::format((string) ($snapshot['account_reference'] ?? '')), 'placed' => self::day((string) $order['placed_at']),
            'lines' => $lines, 'total' => Money::ofMinorUnits((int) $order['total_minor'], $currency)->format(), 'paid' => $paid,
            'url' => $base . '/account/orders/' . (int) $order['id'],
        ];
    }

    /**
     * What an order's lines bought, as its emails list them.
     *
     * @param array<string,mixed> $order
     * @return list<array{title:string,detail:string,amount:string}>
     */
    private function lines(array $order): array
    {
        $lines = [];
        foreach ($this->records->items((int) $order['id']) as $item) {
            $details = CommerceRepository::decode((string) $item['snapshot']);
            $period = AccessPeriod::label((int) $item['access_period_seconds']);
            $lines[] = match ((string) $item['product_type']) {
                'bundle' => ['title' => 'Bundle: ' . ($details['bundle_title'] ?? ''), 'detail' => count((array) ($details['courses'] ?? [])) . ' courses, ' . $period . ' of access each'],
                'company_credit' => ['title' => (int) $item['quantity'] . ' course credits: ' . ($details['course_title'] ?? ''), 'detail' => $period . ' of access each'],
                default => ['title' => (string) ($details['course_title'] ?? ''), 'detail' => $period . ' of access'],
            } + ['amount' => Money::ofMinorUnits((int) $item['amount_minor'] - (int) ($item['discount_minor'] ?? 0), (string) $order['currency'])->format()];
        }

        return $lines;
    }

    /**
     * How to pay an unpaid EFT order: what it is for, what is paid and still to pay, the deadline and
     * what happens on payment. With $paymentId, the part payment that just arrived.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $snapshot
     * @return array{number:string,name:string,account_reference:string,placed:string,lines:list<array{title:string,detail:string,amount:string}>,total:string,paid:string,due:string,pay_by:string,deadline:string,next:string,received:string,url:string}
     */
    private function instructions(array $order, array $snapshot, string $base, ?string $paymentId): array
    {
        $currency = (string) $order['currency'];
        $id = (int) $order['id'];
        $paid = $this->records->paidTotal($id);
        $courses = 0;
        foreach ($this->records->items($id) as $item) {
            $courses += $item['product_type'] === 'bundle' ? count((array) (CommerceRepository::decode((string) $item['snapshot'])['courses'] ?? [])) : 1;
        }
        $starts = $order['company_id'] !== null ? 'Your course credits are added' : ($courses === 1 ? 'Your course starts' : 'Your courses start');
        $received = '';
        if ($paymentId !== null) {
            $payment = $this->records->payment($paymentId);
            $evidence = $this->records->manualEvidence($paymentId);
            $received = Money::ofMinorUnits((int) $payment['amount_minor'], $currency)->format() . ($payment['gateway'] === 'manual_bank' ? ' by EFT' : ' by card')
                . ' on ' . self::day((string) ($evidence['received_at'] ?? $payment['created_at']));
        }

        return [
            'number' => MoneyRecords::orderNumber($id), 'name' => (string) ($snapshot['purchaser_name'] ?? ''),
            'account_reference' => AccountReference::format((string) ($snapshot['account_reference'] ?? '')), 'placed' => self::day((string) $order['placed_at']),
            'lines' => $this->lines($order), 'total' => Money::ofMinorUnits((int) $order['total_minor'], $currency)->format(),
            'paid' => $paid > 0 ? Money::ofMinorUnits($paid, $currency)->format() : '', 'due' => Money::ofMinorUnits($this->records->dueAmount($id), $currency)->format(),
            'pay_by' => self::moment((string) $order['payment_due_at']), 'deadline' => $this->settings->paymentDue()->words(),
            'next' => $starts . ($paymentId !== null ? ' as soon as the rest is paid.' : ' as soon as your payment is confirmed.'),
            'received' => $received, 'url' => $base . '/account/orders/' . $id,
        ];
    }

    /**
     * An unpaid order cancelled at its deadline: when it was due and where anything paid towards it went.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $snapshot
     * @return array{number:string,name:string,account_reference:string,placed:string,pay_by:string,returned:string,wallet_url:string,invoice:string,url:string,again_url:string}
     */
    private function cancellation(array $order, array $snapshot, string $base): array
    {
        $id = (int) $order['id'];
        $company = $order['company_id'] !== null;
        $paid = $this->records->paidTotal($id);

        return [
            'number' => MoneyRecords::orderNumber($id), 'name' => (string) ($snapshot['purchaser_name'] ?? ''),
            'account_reference' => AccountReference::format((string) ($snapshot['account_reference'] ?? '')), 'placed' => self::day((string) $order['placed_at']),
            'pay_by' => self::moment((string) $order['payment_due_at']), 'returned' => $paid > 0 ? Money::ofMinorUnits($paid, (string) $order['currency'])->format() : '',
            'wallet_url' => $base . ($company ? '/company/wallet' : '/account/wallet'), 'invoice' => (string) ($this->invoice($id)['number'] ?? ''),
            'url' => $base . '/account/orders/' . $id, 'again_url' => $base . ($company ? '/company/credits/buy' : '/courses'),
        ];
    }

    /** @return array<string,mixed>|null the order's invoice */
    private function invoice(int $orderId): ?array
    {
        foreach ($this->records->documents($orderId) as $document) {
            if ($document['kind'] === 'invoice') return $document;
        }

        return null;
    }

    /** @return array<string,string> every receipt of the order, number => its PDF */
    private function receipts(int $orderId): array
    {
        $receipts = [];
        foreach ($this->records->documents($orderId) as $document) {
            if ($document['kind'] === 'receipt') $receipts[(string) $document['number']] = $this->documents->pdf($document);
        }

        return $receipts;
    }

    /** "19 October 2026", in the platform's time zone. */
    private static function day(string $moment): string
    {
        return (new \DateTimeImmutable($moment))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j F Y');
    }

    /** "26 October 2026 at 06:13", in the platform's time zone. */
    private static function moment(string $moment): string
    {
        return (new \DateTimeImmutable($moment))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('j F Y \a\t H:i');
    }

    public function deliverCompanyCourseNotices(int $limit = 20): void
    {
        for ($i=0; $i<$limit; $i++) {
            $found = $this->transactions->run(function (): bool {
                $event = $this->records->nextCompanyCourseNotice();
                if ($event === null) return false;
                try {
                    $payload = CommerceRepository::decode((string)$event['payload']);
                    if ($event['event'] === 'company.request_decision') {
                        $this->mailer->sendCourseRequestDecision((string)$payload['email'], (string)$payload['course_title'], true);
                    } else {
                        $this->mailer->sendCourseEnrolmentNotice((string)$payload['email'], (string)$payload['course_title'], (string)$payload['slug']);
                    }
                    $this->records->emailDelivered((int)$event['id'], null);
                } catch (\Throwable) {
                    $this->records->emailDelivered((int)$event['id'], 'Company course notice delivery failed; retry scheduled.');
                }
                return true;
            });
            if (!$found) return;
        }
    }
}
