<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the tabbed course editor: link tabs, the wide form grid and the inline category picker. */
final class CourseEditorTabsContractTest extends TestCase
{
    public function testTabsAreNativeLinksThatMarkTheCurrentPage(): void
    {
        $html = ComponentHarness::render('layout.tabs', ['aria_label' => 'Course editor sections', 'current' => 'pricing', 'items' => [
            ['key' => 'overview', 'label' => 'Overview', 'href' => '/admin/courses/7'],
            ['key' => 'pricing', 'label' => 'Pricing & <access>', 'href' => '/admin/courses/7?tab=pricing'],
        ]]);
        self::assertStringContainsString('<nav class="cl-group-nav" aria-label="Course editor sections">', $html);
        self::assertStringContainsString('href="/admin/courses/7?tab=pricing" aria-current="page"', $html);
        self::assertStringContainsString('Pricing &amp; &lt;access&gt;', $html);
        self::assertSame(1, substr_count($html, 'aria-current'));
        self::assertSame(1, substr_count($html, 'is-current'));
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('role="tab', $html);
    }

    public function testTabItemsAreBounded(): void
    {
        foreach ([[], [['key' => 'a', 'label' => 'A']], [['key' => 'a', 'label' => 'A', 'href' => '/', 'class' => 'x']], [['key' => '', 'label' => 'A', 'href' => '/']]] as $items) {
            try { (new PlatformUi())->properties('layout.tabs', ['items' => $items, 'aria_label' => 'Tabs']); self::fail('Invalid tab items accepted'); }
            catch (\InvalidArgumentException $exception) { self::assertStringContainsString('ab', $exception->getMessage()); }
        }
    }

    public function testWideGridFieldsStateTheirSpan(): void
    {
        self::assertStringContainsString('data-columns="wide"', ComponentHarness::render('form.grid', ['columns' => 'wide']));
        foreach (['quarter', 'third', 'half', 'two-thirds'] as $span) {
            self::assertStringContainsString('cl-ui-field--' . $span, ComponentHarness::render('form.field', ['label' => 'Title', 'span' => $span]));
            self::assertStringContainsString('cl-ui-field--' . $span, ComponentHarness::render('form.choice', ['label' => 'Active', 'span' => $span]));
        }
        $full = ComponentHarness::render('form.field', ['label' => 'Title', 'span' => 'third', 'full_width' => true]);
        self::assertStringContainsString('cl-ui-field--full', $full);
        self::assertStringNotContainsString('cl-ui-field--third', $full);
        try { (new PlatformUi())->properties('form.field', ['label' => 'Title', 'span' => '7']); self::fail('Invalid span accepted'); }
        catch (\InvalidArgumentException $exception) { self::assertStringContainsString('Invalid semantic UI value', $exception->getMessage()); }
    }

    public function testWideGridCssGivesEverySpanAColumnCount(): void
    {
        $css = (string) file_get_contents(RenderHarness::root() . '/public_html/css/catto-platform.css');
        self::assertStringContainsString('.cl-ui-form-grid[data-columns="wide"]{grid-template-columns:repeat(12,minmax(0,1fr))', $css);
        foreach (['quarter' => 'span 3', 'third' => 'span 4', 'two-thirds' => 'span 8'] as $span => $rule) {
            self::assertStringContainsString('.cl-ui-form-grid[data-columns="wide"]>.cl-ui-field--' . $span . '{grid-column:' . $rule . '}', $css);
        }
        self::assertStringContainsString('.cl-group-nav{display:flex;flex-wrap:nowrap', $css);
        self::assertStringContainsString('@media(min-width:721px){.cl-group-nav{flex-wrap:wrap;overflow:visible}}', $css);
        self::assertStringContainsString('.cl-ui-stat-grid[data-density="compact"]{grid-template-columns:repeat(auto-fill,minmax(min(100%,8.5rem),1fr))', $css);
        self::assertStringContainsString('data-density="compact"', ComponentHarness::render('data.stat-grid', ['density' => 'compact']));
        self::assertStringNotContainsString('data-density', ComponentHarness::render('data.stat-grid', []));
    }

    /** The "+ New" category control has regressed repeatedly; every hook the inline create script needs must sit inside one picker. */
    public function testCourseFieldsCategoryPickerIsWiredForInlineCreate(): void
    {
        $course = ['title' => 'T', 'slug' => 't', 'subtitle' => '', 'category_id' => 2, 'level' => '', 'estimated_minutes' => 0,
            'summary' => '', 'introduction_html' => '', 'description_html' => '', 'access_days' => 365, 'show_outline_on_intro' => false];
        $html = RenderHarness::render('partials/admin-course-fields.html.twig', RenderHarness::hiveWith([
            'course' => $course, 'categories' => [['id' => 2, 'name' => 'Health']], 'can_manage_categories' => true,
        ]));
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        $xpath = new \DOMXPath($document);
        self::assertSame(1.0, $xpath->evaluate('count(//*[@data-category-picker])'));
        foreach (['select[@data-category-select]', 'button[@data-category-create-toggle][@aria-expanded="false"]', '*[@data-category-create-panel][@data-create-url][@data-csrf]', 'button[@data-category-create]', 'button[@data-category-create-cancel]', 'input[@data-category-name]'] as $hook) {
            self::assertSame(1.0, $xpath->evaluate('count(//*[@data-category-picker]//' . $hook . ')'), 'Category picker is missing ' . $hook);
        }
        self::assertSame(1.0, $xpath->evaluate('count(//div[@data-columns="wide"])'));
        self::assertSame(1.0, $xpath->evaluate('count(//div[contains(@class,"cl-ui-field--two-thirds")]//input[@id="title"])'));
    }
}
