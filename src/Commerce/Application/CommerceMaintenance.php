<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Commerce\Infrastructure\{CommerceRepository, InvoicePdfRenderer};
use CattoLearning\Infrastructure\Mail\MailerInterface;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use Symfony\Component\Clock\ClockInterface;

/** Applies elapsed commerce deadlines and retries requested invoice delivery in bounded batches. */
final class CommerceMaintenance
{
    public function __construct(private readonly CommerceRepository $records, private readonly OrderService $orders,
        private readonly AccessService $access, private readonly InvoicePdfRenderer $pdfs,
        private readonly MailerInterface $mailer, private readonly TransactionManager $transactions,
        private readonly ClockInterface $clock) {}

    public function run(): void
    {
        $now = $this->clock->now()->format(DATE_ATOM);
        foreach ($this->records->dueOrders($now, 200) as $id) $this->orders->cancelDue($id);
        foreach ($this->records->dueEntitlements($now, 200) as $id) $this->access->reconcile($id);
        $this->deliverInvoices();
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
                        $this->mailer->sendInvoice((string) $snapshot['purchaser_email'], (string) $document['number'], $this->pdfs->render($document));
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
}
