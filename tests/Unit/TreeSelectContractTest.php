<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/**
 * Guards form.tree-select, the one category picker: a one-row control opening a native popover that
 * holds the hierarchy as nested lists, each option with a separate disclosure button for its
 * branch (shown by core script, which also closes every branch except those around the chosen
 * option whenever the list opens), options that submit their value (submit mode) or are radios
 * sent with the form's own Save (field mode), and the full path of the chosen option in the closed
 * control. Everything works without JavaScript: the branches are rendered open.
 */
final class TreeSelectContractTest extends TestCase
{
    private const ITEMS = [
        ['value' => '', 'label' => 'All categories', 'depth' => 0, 'path' => 'All categories'],
        ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'children' => [
            ['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management', 'children' => [
                ['value' => '3', 'label' => 'Project Management', 'depth' => 3, 'path' => 'Business › Management › Project Management'],
            ]],
            ['value' => '4', 'label' => 'Finance', 'depth' => 2, 'path' => 'Business › Finance'],
        ]],
        ['value' => '5', 'label' => 'Technology', 'depth' => 1, 'path' => 'Technology'],
    ];

    public function testTheHierarchyNestsWithDisclosureButtonsBesideTheOptions(): void
    {
        $html = ComponentHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => self::ITEMS, 'selected' => '3', 'placeholder' => 'All categories', 'list_label' => 'Course categories']);
        $xpath = self::xpath($html);
        $toggle = $xpath->query('//button[@type="button" and @id="picker" and @popovertarget="picker-options"]');
        self::assertSame(1, $toggle->length);
        self::assertSame('Business › Management › Project Management', trim((string) $toggle->item(0)?->textContent), 'The closed control shows the full path of the choice.');
        $top = $xpath->query('//*[@id="picker-options" and @popover]/ul[@aria-label="Course categories"]/li');
        self::assertSame(3, $top->length, 'The top level holds the depth-0 choice and the main categories only.');
        self::assertSame(1, $xpath->query('//ul[@aria-label="Course categories"]/li/ul[@id="picker-branch-1" and @role="group"]/li/ul[@id="picker-branch-2"]/li//button[@value="3"]')->length, 'Three levels nest as ul > li > ul.');
        $expanders = $xpath->query('//button[contains(@class,"cl-ui-tree-select-expand")]');
        self::assertSame(2, $expanders->length, 'Only options with children have a disclosure button.');
        foreach ($expanders as $expander) {
            self::assertInstanceOf(\DOMElement::class, $expander);
            self::assertSame(['button', 'true'], [$expander->getAttribute('type'), $expander->getAttribute('aria-expanded')], 'Rendered open, so the tree works without JavaScript.');
            self::assertTrue($expander->hasAttribute('hidden'), 'Shown by core script.');
            self::assertSame(1, $xpath->query('//ul[@id="' . $expander->getAttribute('aria-controls') . '"]')->length);
            self::assertStringStartsWith('Show what is inside ', $expander->getAttribute('aria-label'));
            self::assertFalse($expander->hasAttribute('name'), 'A disclosure button never chooses.');
        }
        self::assertSame(0, $xpath->query('//button[contains(@class,"cl-ui-tree-select-expand")]//button | //button[contains(@class,"cl-ui-tree-select-option")]//button')->length, 'The disclosure button sits beside the option, not inside it.');
        self::assertSame(6, $xpath->query('//*[@popover]//button[@type="submit" and @name="category"]')->length, 'Every level can be chosen.');
        self::assertSame('3', self::attribute($xpath, '//button[@aria-current="true" and @autofocus]', 'value'), 'Opening the list focuses the chosen option.');
        self::assertSame('Business › Management', self::attribute($xpath, '//button[@value="2"]', 'aria-label'));
        $script = (string) file_get_contents(\CattoLearning\Tests\Support\RenderHarness::root() . '/public_html/js/platform-overrides.js');
        foreach (["'.cl-ui-tree-select-expand'", "'beforetoggle'", "event.newState === 'open'", 'showChosen()', 'button.hidden = false', 'window.CattoTreeSelect'] as $contract) {
            self::assertStringContainsString($contract, $script);
        }
    }

    public function testFieldModeStoresTheChoiceInRadiosForTheFormsOwnSave(): void
    {
        $items = [
            ['value' => '0', 'label' => 'Uncategorised', 'depth' => 0, 'path' => 'Uncategorised'],
            ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business', 'children' => [['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management']]],
        ];
        $html = ComponentHarness::render('form.tree-select', ['id' => 'category', 'name' => 'category_id', 'mode' => 'field', 'items' => $items, 'selected' => '2', 'placeholder' => 'Uncategorised', 'list_label' => 'Course categories']);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//div[@data-tree-select="field"]')->length);
        self::assertSame('Business › Management', trim((string) $xpath->query('//button[@id="category"]')->item(0)?->textContent));
        self::assertSame(0, $xpath->query('//button[@type="submit"]')->length, 'Choosing must not submit the form.');
        self::assertSame(1, $xpath->query('//*[@popover]/ul[@role="radiogroup" and @aria-label="Course categories"]')->length);
        self::assertSame(3, $xpath->query('//label[contains(@class,"cl-ui-tree-select-option")]/input[@type="radio" and @name="category_id"]')->length);
        self::assertSame('2', self::attribute($xpath, '//input[@checked and @autofocus]', 'value'));
        self::assertSame('Uncategorised', self::attribute($xpath, '//input[@value="0"]', 'data-tree-select-path'));
        $uncategorised = self::xpath(ComponentHarness::render('form.tree-select', ['id' => 'category', 'name' => 'category_id', 'mode' => 'field', 'items' => $items, 'selected' => '0', 'placeholder' => 'Uncategorised', 'list_label' => 'Course categories']));
        self::assertSame('Uncategorised', trim((string) $uncategorised->query('//button[@id="category"]')->item(0)?->textContent), 'No category shows Uncategorised.');
        $script = (string) file_get_contents(\CattoLearning\Tests\Support\RenderHarness::root() . '/public_html/js/platform-overrides.js');
        foreach (["select.dataset.treeSelect !== 'field'", 'hidePopover()', 'treeSelectPath'] as $contract) self::assertStringContainsString($contract, $script);
    }

    public function testNothingChosenShowsThePlaceholder(): void
    {
        $html = ComponentHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => array_slice(self::ITEMS, 1), 'selected' => '', 'placeholder' => 'All categories', 'list_label' => 'Course categories']);
        self::assertSame('All categories', trim((string) self::xpath($html)->query('//button[@id="picker"]')->item(0)?->textContent));
    }

    public function testOptionsAndIdentifiersAreBounded(): void
    {
        $leaf = ['value' => '3', 'label' => 'C', 'depth' => 3, 'path' => 'A › B › C'];
        foreach ([
            ['id' => 'Bad id', 'items' => self::ITEMS],
            ['id' => 'picker', 'items' => []],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 4, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => 1, 'label' => 'A', 'depth' => 1, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'class' => 'x']]],
            ['id' => 'picker', 'items' => [['value' => '2', 'label' => 'B', 'depth' => 2, 'path' => 'B']]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'children' => [$leaf]]]],
            ['id' => 'picker', 'items' => [['value' => '0', 'label' => 'None', 'depth' => 0, 'path' => 'None', 'children' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A']]]]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'children' => [['value' => '2', 'label' => 'B', 'depth' => 2, 'path' => 'A › B', 'children' => [['value' => '3', 'label' => 'C', 'depth' => 3, 'path' => 'A › B › C', 'children' => [['value' => '4', 'label' => 'D', 'depth' => 4, 'path' => 'D']]]]]]]]],
        ] as $properties) {
            try {
                (new PlatformUi())->properties('form.tree-select', $properties + ['name' => 'category', 'list_label' => 'Categories']);
                self::fail('Invalid tree select accepted: ' . json_encode($properties));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('tree select', $exception->getMessage());
            }
        }
        try {
            (new PlatformUi())->properties('form.tree-select', ['id' => 'parent', 'name' => 'parent_id', 'mode' => 'dropdown', 'items' => self::ITEMS, 'list_label' => 'Parents']);
            self::fail('Unknown mode accepted');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('tree select', $exception->getMessage());
        }
    }

    private static function attribute(\DOMXPath $xpath, string $query, string $name): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertInstanceOf(\DOMElement::class, $node, $query);
        return $node->getAttribute($name);
    }

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return new \DOMXPath($document);
    }
}
