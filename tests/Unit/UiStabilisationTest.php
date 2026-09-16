<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Application\PlatformAdministrationService;
use CattoLearning\Support\Pagination;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\UiOwnershipAudit;
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
        foreach ([0 => 'Page 1 of 1', 1 => 'Page 1 of 1', 101 => 'Showing 1–25 of 101 · Page 1 of 5'] as $total => $expected) {
            $payload = PlatformAdministrationService::paginationPayload('example', Pagination::create(1, 25, $total), '/example', 'Examples');
            $document = new DOMDocument();
            @$document->loadHTML('<?xml encoding="utf-8" ?>' . RenderHarness::render('partials/pagination', ['pg' => $payload]));
            $dom = new DOMXPath($document);
            $summary = (string) $dom->evaluate('string(//*[@class="pagination-summary"])');
            self::assertSame($expected, trim((string) preg_replace('/\s+/u', ' ', $summary)));
            self::assertSame($total > 25 ? 1.0 : 0.0, $dom->evaluate('count(//form[@class="pagination-jump" and @method="get"])'));
        }
    }

    public function testOwnershipAuditRejectsTheActualCssFailureModes(): void
    {
        foreach ([
            '.cl-ui-section-head::before{content:"";background:gold}',
            '@media(max-width:640px){.pagination-controls{flex-wrap:wrap}}',
            '.pagination-row{flex-direction:column}',
            '.company-context{align-items:center}',
            '.dataset-search{width:500px}',
            '.cl-course-card{display:grid}',
            '.cl-ui-section-head,.cl-ui-section-head::before{content:"";position:absolute;pointer-events:none}',
            ':is(.gn-family-admin,.gn-family-account,{color:red}',
            '.cl-ui-stat-card{color:red}}',
        ] as $broken) {
            self::assertNotSame([], UiOwnershipAudit::themeGeometry($broken, 'broken.css'), $broken);
        }
        self::assertSame([], UiOwnershipAudit::themeGeometry(<<<'CSS'
:root{--cl-context-surface:#efdca9}
.gn-footer{display:grid;grid-template-columns:1fr 1fr}
.fs-shell{display:grid;min-height:100vh}
:is(.gn-family-admin,.gn-family-company) .cl-ui-section-head::before,
.gn-family-account .cl-ui-section-head::before{content:"";position:absolute;pointer-events:none;inset:0;background:gold}
.cl-ui-section-head h2::after{content:"";position:absolute;pointer-events:none;bottom:0;width:92px;height:5px;background:gold}
.pagination-row{background:#def;border-radius:10px}
CSS, 'decorative.css'));
    }
}
