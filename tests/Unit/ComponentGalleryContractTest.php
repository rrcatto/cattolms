<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the canonical ComponentGallery presentation contract. */
final class ComponentGalleryContractTest extends TestCase
{
    public function testGalleryRendersAllFamiliesAndAllSemanticStatesWithoutLiveData(): void
    {
        $html = RenderHarness::render('partials/admin/ui-components', ['gallery_pagination' => \CattoLearning\Application\PlatformAdministrationService::paginationPayload('gallery', \CattoLearning\Support\Pagination::create(2, 25, 75), '/admin/system/ui-components', 'Sample records')]);
        foreach (['cl-ui-surface', 'cl-ui-section-head', 'cl-ui-action', 'cl-ui-badge', 'cl-ui-notice', 'cl-ui-field', 'cl-ui-form-actions', 'cl-ui-toolbar', 'cl-ui-table', 'cl-ui-empty-state', 'cl-ui-stat-card', 'cl-ui-list', 'cl-ui-progress', 'cl-ui-modal', 'cl-ui-accordion-section', 'cl-ui-breadcrumb', 'cl-category-grid', 'cl-course-card', 'cl-tag-browser'] as $class) self::assertStringContainsString($class, $html);
        foreach (['neutral', 'info', 'success', 'warning', 'danger', 'permanent'] as $tone) self::assertStringContainsString('cl-ui-badge--' . $tone, $html);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertSame(1.0, $xpath->evaluate('count(//form/div[@class="cl-ui-compact-action"])'));
        self::assertSame(0.0, $xpath->evaluate('count(//*[@class="cl-ui-compact-action"]//*[@class="cl-ui-compact-action"])'));
        self::assertSame(2, substr_count($html, 'data-pagination="gallery"'));
        self::assertStringContainsString('aria-valuenow="0"', $html);
        self::assertStringContainsString('aria-valuenow="45"', $html);
        self::assertStringContainsString('aria-valuenow="100"', $html);
    }
    public function testGalleryIsARegisteredSystemSectionWithSystemPermission(): void
    {
        $section = (new \CattoLearning\Application\AdministrationSectionRegistry())->get('ui_components');
        self::assertSame('/admin/system/ui-components', $section['route']);
        self::assertStringContainsString('/admin/system/ui-components', \CattoLearning\Tests\Support\RouteTable::signatures());
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/AdminController.php');
        self::assertStringContainsString("'settings', 'ui_components' => 'SYSTEM.SETTING.VIEW'", $source);
    }
}
