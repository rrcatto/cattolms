<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Application\PlatformAdministrationService;
use CattoPHP\Ui\Pagination;
use CattoLearning\Tests\Support\RenderHarness;
use CattoPHP\Ui\Contract\SourceAudit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class UiStabilisationTest extends TestCase
{
    public function testHeadingContentAndActionsHaveDistinctOrderedSlots(): void
    {
        $html = RenderHarness::renderSource(<<<'TWIG'
{% embed ui_template('layout.section-head') with {props: ui_props('layout.section-head', {heading: 'Reports', eyebrow: 'Administration', summary: 'Course performance'})} %}
{% block section_actions %}{{ ui('action.link', {label: 'View all', href: '/admin'}) }}{% endblock %}
{% endembed %}
TWIG, []);
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $dom = new DOMXPath($document);
        self::assertSame('cl-ui-section-head-content', $dom->evaluate('string(//header/*[1]/@class)'));
        self::assertSame('Reports', $dom->evaluate('string(//header/*[1]/h2)'));
        self::assertSame('Administration', $dom->evaluate('string(//header/*[1]/div)'));
        self::assertSame('Course performance', $dom->evaluate('string(//header/*[1]/p)'));
        self::assertSame('/admin', $dom->evaluate('string(//header/*[2][@class="cl-ui-section-actions"]/a/@href)'));
    }

    public function testPaginationSummariesRenderSingleAndMultiplePageStates(): void
    {
        // One page states its rows too, so "Pg 1 of 1" says why there is one page (owner, 2026-10-21).
        foreach ([0 => 'Pg 1 of 1', 1 => '1–1 of 1 · Pg 1 of 1', 101 => '1–25 of 101 · Pg 1 of 5'] as $total => $expected) {
            $payload = PlatformAdministrationService::paginationPayload('example', Pagination::create(1, 25, $total), '/example', 'Examples');
            $document = new DOMDocument();
            @$document->loadHTML('<?xml encoding="utf-8" ?>' . RenderHarness::render('@CattoPHP/ui/data/pagination', ['pg' => $payload]));
            $dom = new DOMXPath($document);
            $summary = (string) $dom->evaluate('string(//*[@class="pagination-summary"])');
            self::assertSame($expected, trim((string) preg_replace('/\s+/u', ' ', $summary)));
            self::assertSame($total > 25 ? 1.0 : 0.0, $dom->evaluate('count(//form[@class="pagination-jump" and @method="get"])'));
        }
    }

    /** CattoPHP's audit tests its own rules; these are cattolms's own functional classes, which a theme leaves alone too. */
    public function testAThemeLeavesTheCourseCardAndCompanyBandToCore(): void
    {
        foreach (['.company-context{align-items:center}', '.cl-course-card{display:grid}'] as $broken) {
            self::assertNotSame([], SourceAudit::themeGeometry($broken, 'broken.css', \CattoLearning\View\Ui\ProjectComponents::FUNCTIONAL_CLASSES), $broken);
        }
        self::assertSame([], SourceAudit::themeGeometry('.cl-course-card{border-color:gold}', 'theme.css', \CattoLearning\View\Ui\ProjectComponents::FUNCTIONAL_CLASSES));
    }
}
