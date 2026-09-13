<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Infrastructure;

use Dompdf\{Dompdf, Options};
use CattoLearning\Support\Money;

/** Renders only escaped invoice snapshots; remote resources and executable PDF templates are disabled. */
final class InvoicePdfRenderer
{
    public function __construct(private readonly CommerceRepository $records) {}

    /** @param array<string,mixed> $document */
    public function render(array $document): string
    {
        $id = (int) $document['id'];
        $cached = $this->records->pdf($id);
        if ($cached !== null) return $cached;
        $snapshot = CommerceRepository::decode((string) $document['snapshot']);
        $escape = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11pt;color:#222}table{width:100%;border-collapse:collapse}td,th{padding:9px;text-align:left;border-bottom:1px solid #ddd}h1{font-size:22pt}</style></head><body>';
        $html .= '<h1>'.$escape(ucfirst((string) $document['kind'])).' '.$escape($document['number']).'</h1>';
        $html .= '<p>Order CL-'.str_pad((string) $document['order_id'],8,'0',STR_PAD_LEFT).'<br>Issued '.$escape($document['issued_at']).'</p>';
        $html .= '<p>'.$escape($snapshot['billing_name']).'<br>'.nl2br($escape($snapshot['billing_address'])).'<br>'.$escape($snapshot['purchaser_email']).'</p>';
        $html .= '<table><thead><tr><th>Course</th><th>Access</th><th>Amount</th></tr></thead><tbody>';
        foreach ($snapshot['items'] as $item) {
            $html .= '<tr><td>'.$escape($item['course_title']).'</td><td>'.((int) $item['access_period_seconds']/86400).' days</td><td>'.$escape(Money::strictMinorUnits((int) $item['line_total_minor'], (string) $item['currency'])->format()).'</td></tr>';
        }
        $html .= '</tbody></table><h2>Total: '.$escape(Money::strictMinorUnits((int) $snapshot['total_minor'], (string) $snapshot['currency'])->format()).'</h2><p>Tax is not charged. An invoice is not proof of payment.</p></body></html>';
        $pdf = new Dompdf(new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'isJavascriptEnabled'=>false]));
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $this->records->savePdf($id, $pdf->output());
    }
}
