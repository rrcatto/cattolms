<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

/**
 * Guards the category tree: ordered nested lists in the shared sortable-tree markup, a drag handle on
 * every row, real disclosure buttons that also work as a GET form, Add subcategory only where a
 * level is left, Manage and the ⋯ move menu beside the name rather than inside the button, and a
 * controller that extends the shared sortable tree and never builds markup.
 */
final class CategoryTreeContractTest extends TestCase
{
    public function testTreeIsNestedListsWithHandlesDisclosureButtonsAndSeparateActions(): void
    {
        $node = static fn(int $id, int $level, string $name, array $children, bool $first, bool $last, bool $open, int $height): array => [
            'id' => $id, 'level' => $level, 'name' => $name, 'course_count' => 1, 'descendant_course_count' => 1, 'children' => $children,
            'is_first_sibling' => $first, 'is_last_sibling' => $last, 'open' => $open, 'height' => $height,
        ];
        $leaf = $node(3, 3, 'Project <Management>', [], true, true, false, 1);
        $branch = $node(2, 2, 'Management', [$leaf], true, false, true, 2);
        $root = $node(1, 1, 'Business', [$branch], false, true, false, 3);
        $html = RenderHarness::render('partials/admin/category-tree-node.html.twig', RenderHarness::hiveWith(['node' => $root]));
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body><ol>' . $html . '</ol></body>');
        $xpath = new \DOMXPath($document);

        self::assertSame(1, $xpath->query('/html/body/ol/li/ol[@id="category-branch-1"]/li/ol[@id="category-branch-2"]/li[@id="category-3"]')->length, 'Three levels nest as ol > li > ol.');
        self::assertSame(['1', '3'], [$xpath->evaluate('string(//li[@id="category-1"]/@data-depth)'), $xpath->evaluate('string(//li[@id="category-1"]/@data-height)')], 'Each row carries its depth and the height of its subtree for the drop rules.');
        self::assertSame(3, $xpath->query('//li[@data-node-id]/div[contains(@class,"cl-tree-row")]/button[contains(@class,"cl-tree-handle") and @hidden and @aria-label]')->length, 'Every row has a drag handle, shown by the script.');
        $toggles = $xpath->query('//button[contains(@class,"cl-tree-toggle")]');
        self::assertSame(2, $toggles->length, 'Only categories with subcategories get a disclosure button.');
        foreach ($toggles as $toggle) {
            self::assertInstanceOf(\DOMElement::class, $toggle);
            self::assertSame(['submit', 'category-tree-view', 'toggle', 'category-tree#toggle'], [$toggle->getAttribute('type'), $toggle->getAttribute('form'), $toggle->getAttribute('name'), $toggle->getAttribute('data-action')]);
            self::assertSame(1, $xpath->query('//ol[@id="' . $toggle->getAttribute('aria-controls') . '"]')->length, 'aria-controls names the child list.');
        }
        self::assertSame('false', $xpath->evaluate('string(//li[@id="category-1"]/div//button[contains(@class,"cl-tree-toggle")]/@aria-expanded)'));
        self::assertSame(1, $xpath->query('//ol[@id="category-branch-1" and @hidden]')->length, 'A closed branch is hidden on the server, so it does not flash open.');
        self::assertSame(1, $xpath->query('//ol[@id="category-branch-2" and not(@hidden)]')->length, 'An open one is not.');
        self::assertSame(0, $xpath->query('//li[@id="category-3"]/ol')->length, 'A sub-subcategory holds no list.');
        self::assertStringContainsString('Project &lt;Management&gt;', $html);
        self::assertSame(2, $xpath->query('//a[starts-with(normalize-space(.),"Add subcategory") and contains(@href,"/new")]')->length, 'Add subcategory on levels 1 and 2 only.');
        self::assertSame(3, $xpath->query('//a[contains(@class,"cl-ui-action") and starts-with(normalize-space(.),"Manage")]')->length);
        self::assertSame(0, $xpath->query('//button[contains(@class,"cl-tree-toggle")]//a | //button[contains(@class,"cl-tree-toggle")]//form')->length, 'Manage and the moves sit outside the disclosure button.');
        foreach (['top', 'up', 'down', 'bottom', 'out'] as $direction) {
            self::assertSame(3, $xpath->query('//form[@data-tree-direction="' . $direction . '" and contains(@action,"/move")]')->length, 'Every row can move ' . $direction . ' without JavaScript.');
        }
        self::assertSame(1, $xpath->query('//li[@id="category-1"]/div//form[@data-tree-direction="out"]//button[@disabled]')->length, 'A main category cannot move out.');
        self::assertSame(1, $xpath->query('//li[@id="category-1"]/div//form[@data-tree-direction="down"]//button[@disabled]')->length, 'The last sibling cannot move down.');
        self::assertSame(3, $xpath->query('//a[contains(@href,"/move-into")]')->length);
    }

    public function testPageAndControllerUseTheSharedSortableTree(): void
    {
        $root = RenderHarness::root();
        $page = (string) file_get_contents($root . '/resources/views/pages/admin-course-categories.html.twig');
        foreach (['{{ importmap() }}', 'data-controller="category-tree"', 'dragstart->category-tree#start', 'drop->category-tree#drop', 'submit->category-tree#menu', 'category-tree#expandAll', 'category-tree#collapseAll', 'id="category-tree-view"', 'method="get"', 'data-category-tree-target="enhanced" hidden', "modal: 'category-create'"] as $contract) {
            self::assertStringContainsString($contract, $page);
        }
        self::assertGreaterThan(strpos($page, 'page-head.html.twig'), strpos($page, '{{ importmap() }}'), 'The page head stays the first element of main; scripts come after it.');
        $shared = (string) file_get_contents($root . '/assets/lib/sortable_tree_controller.js');
        $controller = (string) file_get_contents($root . '/assets/controllers/category_tree_controller.js');
        $content = (string) file_get_contents($root . '/assets/controllers/course_content_controller.js');
        foreach ([$shared, $controller, $content] as $source) {
            foreach (['innerHTML', 'createElement', 'insertAdjacentHTML', 'classList'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, 'The trees move server-rendered rows and swap in the server\'s tree; they never build markup.');
            }
        }
        self::assertStringContainsString("from '../lib/sortable_tree_controller.js'", $controller);
        self::assertStringContainsString("from '../lib/sortable_tree_controller.js'", $content, 'Course Content uses the same tree behaviour.');
        foreach (["setAttribute('aria-expanded'", 'list.hidden = !expanded', 'sessionStorage', 'dataset.drop = zone', 'depth + height <= this.maxDepth', "'HX-Request': 'true'"] as $contract) {
            self::assertStringContainsString($contract, $shared);
        }
        self::assertStringContainsString('<symbol id="action-drag"', (string) file_get_contents($root . '/public_html/img/nav-icons.svg'));
    }
}
