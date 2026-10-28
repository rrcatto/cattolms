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
        self::assertStringNotContainsString('cl-ui-stat-card--large', $html);
        // The one figure a page is opened for, such as a wallet's balance (owner, 2026-10-21).
        self::assertStringContainsString('class="cl-ui-stat-card cl-ui-stat-card--large"', ComponentHarness::render('data.stat-card', ['value' => 'R10.00', 'label' => 'Wallet balance', 'size' => 'large']));
        // A money amount in a row of figures (owner, 2026-10-21: "-2pt, possibly -4pt"), and a card's icon (owner: "Dashboard
        // cards also need svg icons").
        $small = ComponentHarness::render('data.stat-card', ['value' => 'R54,177.05', 'label' => 'Money received', 'size' => 'small', 'icon' => 'figure-money-in', 'href' => '/admin/commerce/ledger']);
        self::assertStringContainsString('<a class="cl-ui-stat-card cl-ui-stat-card--small cl-ui-stat-link" href="/admin/commerce/ledger"><span class="cl-ui-stat-icon">', $small);
        self::assertStringContainsString('#figure-money-in', $small);
        self::assertStringContainsString('<div class="cl-ui-stat-value"><span>R54,177.05</span></div>', $small);
        self::assertStringNotContainsString('cl-ui-stat-icon', $html, 'No icon unless one is named.');
        self::assertStringContainsString('data-columns="5"', ComponentHarness::render('data.stat-grid', ['columns' => 5]));
        try {
            ComponentHarness::render('data.stat-card', ['value' => 1, 'label' => 'x', 'size' => 'huge']);
            self::fail('An unknown stat card size was accepted.');
        } catch (\Throwable $refused) {
            self::assertStringContainsString('size', $refused->getMessage());
        }
        self::assertStringContainsString('&lt;value&gt;', ComponentHarness::render('data.key-value-list', ['items' => [['label' => 'Name', 'value' => '<value>']]]));
        // A value marked down from a was value (owner, 2026-10-20): the was value struck through first, named for screen readers.
        $marked = ComponentHarness::render('data.key-value-list', ['items' => [['label' => 'Price', 'value' => 'R1,800.00', 'was' => '<R2,400.00>']]]);
        self::assertStringContainsString('<dd><s class="cl-ui-key-value-was"><span class="visually-hidden">Was </span>&lt;R2,400.00&gt;</s> <span class="visually-hidden">now </span>R1,800.00</dd>', $marked);
        self::assertStringNotContainsString('<s', ComponentHarness::render('data.key-value-list', ['items' => [['label' => 'Price', 'value' => 'R1,800.00', 'was' => null]]]));
    }
    /**
     * A person with an account (owner, 2026-10-21): their name linked to their profile; the page's own person (full) once,
     * with the account reference and email each on its own line. Every other mention, and every table, the name alone.
     */
    public function testAPersonIsTheirNameAndAccountReferenceLinkedToTheirAccount(): void
    {
        $name = ComponentHarness::render('data.person', ['name' => '<Thandi Nkosi>', 'reference' => 'CL202610200001X', 'href' => '/admin/people/7', 'email' => 'thandi@example.test']);
        self::assertStringContainsString('<span class="cl-ui-person"><a class="cl-ui-person-account" href="/admin/people/7"><span class="cl-ui-person-name">&lt;Thandi Nkosi&gt;</span></a></span>', $name);
        self::assertStringNotContainsString('CL-', $name, 'A mention names the person without their reference.');
        self::assertStringNotContainsString('mailto:', $name, 'or their email.');
        $full = ComponentHarness::render('data.person', ['name' => 'Thandi Nkosi', 'reference' => 'CL202610200001X', 'href' => '/admin/people/7', 'email' => 'thandi@example.test', 'full' => true]);
        self::assertStringContainsString('<span class="cl-ui-person cl-ui-person--full"><span class="cl-ui-person-line"><a class="cl-ui-person-account" href="/admin/people/7"><span class="cl-ui-person-name">Thandi Nkosi</span></a></span><span class="cl-ui-person-line cl-ui-person-reference">CL-20261020-0001-X</span><span class="cl-ui-person-line"><a class="cl-ui-person-email" href="mailto:thandi@example.test">thandi@example.test</a></span></span>', $full);
        $plain = ComponentHarness::render('data.person', ['name' => 'Sender']);
        self::assertStringNotContainsString('<a', $plain, 'No account to open: the name alone.');
    }

    /**
     * A list shown as cards (owner, 2026-10-26): whole rows of 4 or 5, each card its picture, its title (linked when it has
     * a page), details and actions, the picture's shape named; nothing else is accepted.
     */
    public function testACardGridHoldsCardsWithAPictureTitleDetailsAndActions(): void
    {
        $grid = ComponentHarness::render('data.card-grid', ['columns' => 5, 'label' => '<People>']);
        self::assertStringContainsString('<ul class="cl-ui-card-grid" data-columns="5" aria-label="&lt;People&gt;">', $grid);
        self::assertStringContainsString('data-columns="4"', ComponentHarness::render('data.card-grid', []), 'Four to a row unless five are asked for.');
        $card = ComponentHarness::render('data.card', ['title' => '<Thandi>', 'href' => '/admin/people/7', 'picture' => 'round']);
        self::assertStringContainsString('<li class="cl-ui-card" data-picture="round">', $card);
        self::assertStringContainsString('<h3 class="cl-ui-card-title"><a href="/admin/people/7">&lt;Thandi&gt;</a></h3>', $card);
        self::assertStringContainsString('<h3 class="cl-ui-card-title">Gauteng Logistics</h3>', ComponentHarness::render('data.card', ['title' => 'Gauteng Logistics', 'picture' => 'square']), 'No page: the name alone.');
        foreach ([['data.card-grid', ['columns' => 6], 'columns'], ['data.card-grid', ['columns' => 'auto'], 'columns'], ['data.card', ['title' => 'x', 'picture' => 'oval'], 'picture']] as [$component, $properties, $key]) {
            try {
                ComponentHarness::render($component, $properties);
                self::fail($component . ' accepted ' . json_encode($properties));
            } catch (\Throwable $refused) {
                self::assertStringContainsString($key, $refused->getMessage());
            }
        }
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
