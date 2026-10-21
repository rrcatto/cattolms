<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Http\Controller\LegalController;
use CattoLearning\Legal\LegalDocuments;
use CattoLearning\Tests\Support\{InProcessPage, IntegrationContainer};
use CattoLearning\View\ThemeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The Terms, the Refund Policy and the Privacy Policy as PDFs (owner, 2026-10-20: "a properly formatted PDF that
 * features the name of the site, the Last Update date and all the information under neat headings, and at the
 * bottom of each one, my name, address, contact details"), linked from each page in place of "You can print this
 * page or save it from your browser".
 */
final class LegalPdfIntegrationTest extends TestCase
{
    public function testEachDocumentIsAPdfLinkedFromItsPage(): void
    {
        foreach (['terms' => 'termsPdf', 'refunds' => 'refundsPdf', 'privacy' => 'privacyPdf'] as $key => $action) {
            $document = IntegrationContainer::get()->get(LegalDocuments::class)->document($key);
            $response = InProcessPage::run(null, LegalController::class, $action);
            self::assertSame(200, $response['status'], $key);
            self::assertStringStartsWith('%PDF-', $response['body'], $key . ' is a PDF.');
            self::assertSame('application/pdf', $response['headers']['content-type']);
            self::assertSame('inline; filename="' . str_replace(' ', '-', $document['title']) . '-' . $document['updated'] . '.pdf"', $response['headers']['content-disposition']);
            $page = InProcessPage::run(null, LegalController::class, $key)['body'];
            self::assertStringContainsString('href="' . $document['path'] . '.pdf"', $page, 'The page links to its PDF.');
            self::assertMatchesRegularExpression('#<a\b[^>]*href="' . preg_quote($document['path'], '#') . '\.pdf"[^>]*target="_blank"[^>]*rel="noopener noreferrer"#', $page, 'The PDF opens in a new tab.');
            self::assertStringNotContainsString('print this page', $page);
        }
    }

    public function testThePdfHoldsTheSiteTheDateEveryNumberedSectionAndTheSellersDetails(): void
    {
        $legal = IntegrationContainer::get()->get(LegalDocuments::class);
        $supplier = $legal->supplier();
        foreach (['terms' => 15, 'refunds' => 10, 'privacy' => 10] as $key => $sections) {
            $document = $legal->document($key);
            $html = IntegrationContainer::get()->get(ThemeRenderer::class)->renderFragment('partials/legal/document-pdf', [
                'title' => $document['title'], 'legal' => $document, 'legal_documents' => $legal->all(), 'supplier' => $supplier,
                'refund_window' => '14 days', 'payment_due' => '7 days', 'wallet_expiry' => '12 months', 'wallet_reminder' => '30 days', 'eft_refund_fee' => 'R0.00',
            ]);
            self::assertMatchesRegularExpression('#<div class="pdf-site">[^<]+</div>#', $html, 'The name of the site.');
            self::assertStringContainsString('<div class="pdf-title">' . $document['title'] . '</div>', $html);
            self::assertStringContainsString('<div class="pdf-updated">Last updated ' . $document['updated_label'] . '</div>', $html);
            for ($number = 1; $number <= $sections; $number++) self::assertStringContainsString('<span class="cl-ui-accordion-eyebrow">Section ' . $number . '</span>', $html, $key . ' section ' . $number);
            self::assertStringNotContainsString('Section ' . ($sections + 1) . '<', $html);
            $foot = substr($html, (int) strpos($html, '<div id="pdf-foot">'), 1200);
            foreach ([(string) $supplier['legal_name'], (string) $supplier['physical_address'], (string) $supplier['phone'], (string) $supplier['email']] as $detail) {
                self::assertStringContainsString(htmlspecialchars($detail, ENT_QUOTES), $foot, 'Every page ends with the seller\'s name, address and contact details.');
            }
            self::assertStringContainsString('<h2>Contact us</h2>', $html, 'The document ends with them in full.');
        }
    }
}
