<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the canonical DataComponent presentation contract. */
final class DataComponentContractTest extends TestCase
{
    public function testStatAndMetadataTextEscapesAndOptionalNoteDisappears(): void
    {
        $html = ComponentHarness::render('data.stat-card', ['value' => 24, 'label' => '<Courses>']);
        self::assertStringContainsString('&lt;Courses&gt;', $html);
        self::assertStringNotContainsString('cl-ui-stat-note', $html);
        self::assertStringContainsString('&lt;value&gt;', ComponentHarness::render('data.key-value-list', ['items' => [['label' => 'Name', 'value' => '<value>']]]));
    }
    public function testDatasetRetainsTwoSharedPagersAndDurableRegion(): void
    {
        $pg = \CattoLearning\Application\PlatformAdministrationService::paginationPayload('example', \CattoLearning\Support\Pagination::create(2, 25, 100), '/example', 'Examples');
        $html = ComponentHarness::render('data.dataset', ['name' => 'example', 'pagination' => $pg]);
        self::assertSame(2, substr_count($html, 'data-pagination="example"'));
        self::assertSame(1, substr_count($html, 'id="example-region"'));
        self::assertSame(1, substr_count($html, 'id="example-results"'));
        self::assertStringContainsString('href="/example?', $html);
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_values(array_unique($ids[1])), 'Paired pagers must give each label and control a unique ID.');
    }
    public function testBreadcrumbIsGenericAndMarksCurrentLocation(): void
    {
        $html = ComponentHarness::render('layout.breadcrumb', ['items' => [['label' => 'Home', 'href' => '/'], ['label' => '<Current>', 'href' => null, 'current' => true]], 'aria_label' => 'Location']);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('&lt;Current&gt;', $html);
        self::assertStringNotContainsString('All categories', $html);
    }
}
