<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

/** Guards the category management tree: nested lists, real disclosure buttons, actions outside them. */
final class CategoryTreeContractTest extends TestCase
{
    public function testTreeIsNestedListsWithDisclosureButtonsAndSeparateActions(): void
    {
        $leaf = ['id' => 3, 'level' => 3, 'name' => 'Project <Management>', 'course_count' => 1, 'descendant_course_count' => 1, 'children' => [], 'is_first_sibling' => true, 'is_last_sibling' => true];
        $branch = ['id' => 2, 'level' => 2, 'name' => 'Management', 'course_count' => 0, 'descendant_course_count' => 1, 'children' => [$leaf], 'is_first_sibling' => true, 'is_last_sibling' => false];
        $root = ['id' => 1, 'level' => 1, 'name' => 'Business', 'course_count' => 2, 'descendant_course_count' => 3, 'children' => [$branch], 'is_first_sibling' => false, 'is_last_sibling' => true];
        $html = RenderHarness::render('partials/admin/category-tree-node.html.twig', RenderHarness::hiveWith(['node' => $root]));
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body><ul>' . $html . '</ul></body>');
        $xpath = new \DOMXPath($document);

        self::assertSame(1, $xpath->query('/html/body/ul/li/ul[@id="category-branch-1"]/li/ul[@id="category-branch-2"]/li')->length, 'Three levels nest as ul > li > ul.');
        $toggles = $xpath->query('//button[@type="button" and @aria-expanded and @aria-controls and @data-action="category-tree#toggle"]');
        self::assertSame(2, $toggles->length, 'Only categories with children get a disclosure button.');
        foreach ($toggles as $toggle) {
            self::assertInstanceOf(\DOMElement::class, $toggle);
            self::assertSame(1, $xpath->query('//ul[@id="' . $toggle->getAttribute('aria-controls') . '"]')->length, 'aria-controls names the child list.');
            self::assertSame(1, $xpath->query('.//*[contains(@class,"cl-ui-icon")]/*[local-name()="use" and contains(@href,"#action-chevron")]', $toggle)->length, 'The chevron comes from the core sprite.');
        }
        self::assertSame(0, $xpath->query('//li[@data-level="3"]//button[@aria-expanded]')->length, 'Third-level categories have no disclosure.');
        self::assertStringContainsString('Project &lt;Management&gt;', $html);
        self::assertSame(3, $xpath->query('//a[@href and contains(@class,"cl-ui-action") and starts-with(normalize-space(.),"Manage")]')->length);
        self::assertSame(0, $xpath->query('//button[@aria-expanded]//a | //button[@aria-expanded]//form')->length, 'Manage and move actions sit outside the disclosure button.');
        self::assertSame(1, $xpath->query('//li[@data-level="1"]/div//form[1]//button[@disabled]')->length + $xpath->query('//li[@data-level="1"]/div//form[2]//button[@disabled]')->length, 'The last sibling cannot move down, the others can.');
        self::assertStringNotContainsString('<details', $html);
    }

    public function testPageLoadsTheControllerAndTheControllerOnlyTogglesRenderedLists(): void
    {
        $root = RenderHarness::root();
        $page = (string) file_get_contents($root . '/resources/views/pages/admin-course-categories.html.twig');
        foreach (['{{ importmap() }}', 'data-controller="category-tree"', 'category-tree#expandAll', 'category-tree#collapseAll', 'data-category-tree-target="controls" hidden'] as $contract) {
            self::assertStringContainsString($contract, $page);
        }
        self::assertGreaterThan(strpos($page, "page-head.html.twig"), strpos($page, '{{ importmap() }}'), 'The page head stays the first element of main; scripts come after it.');
        $controller = (string) file_get_contents($root . '/assets/controllers/category_tree_controller.js');
        foreach (['innerHTML', 'createElement', 'insertAdjacentHTML', 'classList'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $controller, 'The tree controller toggles server-rendered lists; it never builds markup.');
        }
        foreach (["setAttribute('aria-expanded'", 'list.hidden = !expanded', 'sessionStorage'] as $contract) {
            self::assertStringContainsString($contract, $controller);
        }
        self::assertStringContainsString('<symbol id="action-chevron"', (string) file_get_contents($root . '/public_html/img/nav-icons.svg'));
    }
}
