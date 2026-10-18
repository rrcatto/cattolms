<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Commerce\Wallet\WalletOwner;
use CattoLearning\Commerce\Wallet\WalletService;
use CattoLearning\Support\Env;
use CattoLearning\Support\Money;
use CattoLearning\Infrastructure\Mail\MailerInterface;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;

/**
 * Applies elapsed commerce deadlines and retries requested invoice delivery in bounded batches. Writes
 * off wallet credit whose expiry has passed and emails each wallet's reminder before its credit expires
 * (owner, 2026-10-17), once per credit.
 */
final class CommerceMaintenance
{
    public function __construct(private readonly CommerceRepository $records, private readonly OrderService $orders,
        private readonly AccessService $access, private readonly FinancialDocuments $documents,
        private readonly MailerInterface $mailer, private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock, private readonly WalletService $wallets) {}

    public function run(): void
    {
        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($this->records->dueOrders($now, 200) as $id) $this->orders->cancelDue($id);
        foreach ($this->records->dueEntitlements($now, 200) as $id) $this->access->reconcile($id);
        $this->deliverInvoices();
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
