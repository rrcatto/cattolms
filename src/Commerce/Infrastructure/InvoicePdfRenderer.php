<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Infrastructure;

use CattoLearning\Commerce\Domain\BillingDetails;
use Dompdf\{Dompdf, Options};
use CattoLearning\Support\Money;

/**
 * Renders only escaped document snapshots; remote resources and executable PDF templates are
 * disabled. Everything printed comes from the document's own immutable snapshot, never from a
 * current profile, and a document's PDF is generated once and kept.
 */
final class InvoicePdfRenderer
{
    public function __construct(private readonly CommerceRepository $records) {}

    /** @param array<string,mixed> $document */
    public function render(array $document): string
    {
        $id = (int) $document['id'];
        $cached = $this->records->pdf($id);
        if ($cached !== null) return $cached;
        $pdf = new Dompdf(new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false]));
        $pdf->loadHtml($this->html($document), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $this->records->savePdf($id, $pdf->output());
    }

    /**
     * The document as the HTML the PDF is drawn from, built from its snapshot alone.
     *
     * @param array<string,mixed> $document
     */
    public function html(array $document): string
    {
        $snapshot = CommerceRepository::decode((string) $document['snapshot']);
        $escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt;color:#222}table{width:100%;border-collapse:collapse}td,th{padding:9px;text-align:left;border-bottom:1px solid #ddd}h1{font-size:22pt}</style></head><body>';
        $html .= '<h1>'.$escape(ucfirst((string) $document['kind'])).' '.$escape($document['number']).'</h1>';
        $html .= '<p>Order CL-'.str_pad((string) $document['order_id'],8,'0',STR_PAD_LEFT).'<br>Issued '.$escape($document['issued_at']).'</p>';
        $html .= '<h2>Billed to</h2><p>'.implode('<br>', array_map($escape, BillingDetails::fromSnapshot((array) $snapshot['billing'])->documentLines())).'</p>';
        $html .= '<p>Purchased by '.$escape($snapshot['purchaser_name'] ?? '').' ('.$escape($snapshot['purchaser_email'] ?? '').')'.(isset($snapshot['company_name']) ? ' for '.$escape($snapshot['company_name']) : '').'</p>';
        $html .= '<table><thead><tr><th>Course</th><th>Access</th><th>Amount</th></tr></thead><tbody>';
        foreach ($snapshot['items'] as $item) {
            $quantity = (int)($item['quantity'] ?? 1);
            $html .= '<tr><td>'.$escape($item['course_title']).($quantity > 1 ? ' × '.$quantity.' credits' : '').'</td><td>'.((int) $item['access_period_seconds']/86400).' days</td><td>'.$escape(Money::strictMinorUnits((int) $item['line_total_minor'], (string) $item['currency'])->format()).'</td></tr>';
        }
        $html .= '</tbody></table><h2>'.($document['kind']==='credit_note'?'Amount credited: ':'Total: ').$escape(Money::strictMinorUnits((int) $snapshot['total_minor'], (string) $snapshot['currency'])->format()).'</h2>';
        $html .= $document['kind']==='credit_note' ? '<p>Refund basis: '.$escape($snapshot['refund_basis'] ?? '').'. Amount credited to Account Funds; this document does not record a bank payout.</p>' : '<p>Tax is not charged. An invoice is not proof of payment.</p>';
        return $html . '</body></html>';
    }
}
