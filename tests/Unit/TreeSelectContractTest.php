<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards form.tree-select: a one-row popover picker whose options submit their value without JavaScript. */
final class TreeSelectContractTest extends TestCase
{
    private const ITEMS = [
        ['value' => '', 'label' => 'All categories', 'depth' => 0, 'path' => 'All categories'],
        ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business'],
        ['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management'],
        ['value' => '3', 'label' => 'Project Management', 'depth' => 3, 'path' => 'Business › Management › Project Management'],
    ];

    public function testClosedControlShowsTheChosenPathAndTheOpenListMarksIt(): void
    {
        $html = ComponentHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => self::ITEMS, 'selected' => '3', 'placeholder' => 'All categories', 'list_label' => 'Course categories']);
        $xpath = self::xpath($html);
        $toggle = $xpath->query('//button[@type="button" and @id="picker" and @popovertarget="picker-options"]');
        self::assertSame(1, $toggle->length);
        self::assertSame('Business › Management › Project Management', trim((string) $toggle->item(0)?->textContent));
        self::assertSame(1, $xpath->query('//*[@id="picker-options" and @popover]//ul[@aria-label="Course categories"]')->length);
        self::assertSame(4, $xpath->query('//*[@popover]//button[@type="submit" and @name="category"]')->length);
        self::assertSame(1, $xpath->query('//button[@aria-current="true"]')->length);
        self::assertSame('3', self::attribute($xpath, '//button[@aria-current="true"]', 'value'));
        self::assertSame(1, $xpath->query('//button[@aria-current="true" and @autofocus]')->length, 'Opening the list focuses the chosen option.');
        foreach (['', '1', '2', '3'] as $depth => $value) {
            self::assertSame((string) $depth, self::attribute($xpath, '//button[@name="category" and @value="' . $value . '"]', 'data-depth'));
        }
        self::assertSame('Business › Management', self::attribute($xpath, '//button[@value="2"]', 'aria-label'));
    }

    public function testFieldModeStoresTheChoiceInRadiosForTheFormsOwnSave(): void
    {
        $items = [
            ['value' => '0', 'label' => 'No parent (top-level category)', 'depth' => 0, 'path' => 'No parent'],
            ['value' => '1', 'label' => 'Business', 'depth' => 1, 'path' => 'Business'],
            ['value' => '2', 'label' => 'Management', 'depth' => 2, 'path' => 'Business › Management'],
        ];
        $html = ComponentHarness::render('form.tree-select', ['id' => 'parent', 'name' => 'parent_id', 'mode' => 'field', 'items' => $items, 'selected' => '2', 'placeholder' => 'No parent', 'list_label' => 'Possible parents']);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//div[@data-tree-select="field"]')->length);
        self::assertSame('Business › Management', trim((string) $xpath->query('//button[@id="parent"]')->item(0)?->textContent));
        self::assertSame(0, $xpath->query('//button[@type="submit"]')->length, 'Choosing a parent must not submit the form.');
        self::assertSame(1, $xpath->query('//*[@popover]//ul[@role="radiogroup" and @aria-label="Possible parents"]')->length);
        self::assertSame(3, $xpath->query('//label[contains(@class,"cl-ui-tree-select-option")]/input[@type="radio" and @name="parent_id"]')->length);
        self::assertSame(1, $xpath->query('//input[@type="radio" and @checked and @autofocus]')->length);
        self::assertSame('2', self::attribute($xpath, '//input[@checked]', 'value'));
        self::assertSame('No parent', self::attribute($xpath, '//input[@value="0"]', 'data-tree-select-path'));
        self::assertSame('Business › Management', self::attribute($xpath, '//input[@value="2"]', 'aria-label'));
        $script = (string) file_get_contents(\CattoLearning\Tests\Support\RenderHarness::root() . '/public_html/js/platform-overrides.js');
        foreach (['[data-tree-select="field"]', 'hidePopover()', 'treeSelectPath'] as $contract) self::assertStringContainsString($contract, $script);
        try { (new PlatformUi())->properties('form.tree-select', ['id' => 'parent', 'name' => 'parent_id', 'mode' => 'dropdown', 'items' => $items, 'list_label' => 'Parents']); self::fail('Unknown mode accepted'); }
        catch (\InvalidArgumentException $exception) { self::assertStringContainsString('tree select', $exception->getMessage()); }
    }

    public function testNothingChosenShowsThePlaceholder(): void
    {
        $html = ComponentHarness::render('form.tree-select', ['id' => 'picker', 'name' => 'category', 'items' => array_slice(self::ITEMS, 1), 'selected' => '', 'placeholder' => 'All categories', 'list_label' => 'Course categories']);
        self::assertSame('All categories', trim((string) self::xpath($html)->query('//button[@id="picker"]')->item(0)?->textContent));
    }

    public function testOptionsAndIdentifiersAreBounded(): void
    {
        foreach ([
            ['id' => 'Bad id', 'items' => self::ITEMS],
            ['id' => 'picker', 'items' => []],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 4, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => 1, 'label' => 'A', 'depth' => 1, 'path' => 'A']]],
            ['id' => 'picker', 'items' => [['value' => '1', 'label' => 'A', 'depth' => 1, 'path' => 'A', 'class' => 'x']]],
        ] as $properties) {
            try {
                (new PlatformUi())->properties('form.tree-select', $properties + ['name' => 'category', 'list_label' => 'Categories']);
                self::fail('Invalid tree select accepted: ' . json_encode($properties));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('tree select', $exception->getMessage());
            }
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
