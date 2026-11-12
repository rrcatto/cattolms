<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use PHPUnit\Framework\TestCase;

/**
 * The dataset component is CattoPHP's (CattoPHP Phase 3, step 1), with its own tests there; this checks that cattolms's
 * pagination payload (PlatformAdministrationService::paginationPayload) is what the component draws from.
 */
final class DataComponentContractTest extends TestCase
{
    public function testDatasetRetainsTwoSharedPagersAndDurableRegion(): void
    {
        $pg = \CattoLearning\Application\PlatformAdministrationService::paginationPayload('example', \CattoPHP\Ui\Pagination::create(2, 25, 100), '/example', 'Examples');
        $html = ComponentHarness::render('data.dataset', ['name' => 'example', 'pagination' => $pg]);
        self::assertSame(2, substr_count($html, 'data-pagination="example"'));
        self::assertSame(1, substr_count($html, 'id="example-region"'));
        self::assertSame(1, substr_count($html, 'id="example-results"'));
        self::assertStringContainsString('href="/example?', $html);
        preg_match_all('/\bid="([^"]+)"/', $html, $ids);
        self::assertSame($ids[1], array_values(array_unique($ids[1])), 'Paired pagers must give each label and control a unique ID.');
    }
}
