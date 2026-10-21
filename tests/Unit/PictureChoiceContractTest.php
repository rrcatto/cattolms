<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards form.picture-choice: a native radio group whose options are pictures with a label, sent with the form's own Save. */
final class PictureChoiceContractTest extends TestCase
{
    private const ITEMS = [
        ['value' => 'classic', 'label' => 'Classic', 'image' => '/img/certificates/looks/classic.png', 'note' => 'Navy double rule, serif type.'],
        ['value' => 'modern', 'label' => 'Modern <new>', 'image' => '/img/certificates/looks/modern.png', 'note' => ''],
        ['value' => 7, 'label' => 'Uploaded', 'image' => '/admin/certificates/pictures/' . 'ab12.png?w=480'],
    ];

    public function testOptionsAreNativeRadiosInsideTheirLabelsUnderOneLegend(): void
    {
        $html = ComponentHarness::render('form.picture-choice', ['name' => 'look', 'legend' => 'Choose a look', 'items' => self::ITEMS, 'selected' => 'modern', 'help' => 'Every certificate is A4 landscape.', 'id' => 'look-choice']);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//fieldset[@id="look-choice"]/legend[normalize-space()="Choose a look"]')->length);
        self::assertSame(3, $xpath->query('//fieldset//label/input[@type="radio" and @name="look"]')->length, 'Each picture is a labelled native radio, so the choice works without JavaScript.');
        self::assertSame(['modern'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('value'), iterator_to_array($xpath->query('//input[@checked]'))));
        self::assertSame(3, $xpath->query('//label/img[@alt=""]')->length, 'The label names the option; the picture is not read out twice.');
        self::assertSame('Navy double rule, serif type.', trim((string) $xpath->query('//*[contains(@class,"cl-ui-picture-choice-note")]')->item(0)?->textContent));
        self::assertSame(1, $xpath->query('//*[contains(@class,"cl-ui-picture-choice-note")]')->length, 'An empty note prints nothing.');
        self::assertStringContainsString('Modern &lt;new&gt;', $html);
        self::assertStringContainsString('Every certificate is A4 landscape.', $html);
        $integer = self::xpath(ComponentHarness::render('form.picture-choice', ['name' => 'design', 'legend' => 'Design', 'items' => self::ITEMS, 'selected' => 7]));
        $checked = $integer->query('//input[@checked]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $checked);
        self::assertSame('7', $checked->getAttribute('value'), 'A numeric id selects its option.');
    }

    public function testOptionsAreBoundedData(): void
    {
        $ui = new PlatformUi();
        foreach ([
            ['items' => []],
            ['items' => [['value' => 'a', 'label' => 'A']]],
            ['items' => [['value' => 'a', 'label' => '', 'image' => '/a.png']]],
            ['items' => [['value' => 'a', 'label' => 'A', 'image' => 'https://example.com/a.png']]],
            ['items' => [['value' => 'a', 'label' => 'A', 'image' => '//example.com/a.png']]],
            ['items' => [['value' => 'a', 'label' => 'A', 'image' => '/a.png', 'onclick' => 'x()']]],
            ['items' => [['value' => ['a'], 'label' => 'A', 'image' => '/a.png']]],
            ['legend' => ''],
            ['id' => 'Bad id'],
        ] as $broken) {
            try {
                $ui->properties('form.picture-choice', $broken + ['name' => 'look', 'legend' => 'Look', 'items' => self::ITEMS]);
                self::fail('Accepted ' . json_encode($broken));
            } catch (\InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage());
            }
        }
    }

    /**
     * Feature cards (owner, 2026-10-20: the Register page's account type as "a Feature Card Radio Selector (Grid
     * Layout). Each card should have an SVG icon, a Bold Title, and a description"): an option may carry an SVG
     * icon instead of a picture, drawn inline, never anything that runs or links out.
     */
    public function testAnOptionMayBeAFeatureCardWithAnSvgIcon(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="7" r="3.5"/></svg>';
        $html = ComponentHarness::render('form.picture-choice', ['name' => 'account_type', 'legend' => 'Account type', 'selected' => 'individual', 'items' => [
            ['value' => 'individual', 'label' => 'Individual learner', 'icon' => $icon, 'note' => 'For yourself.'],
            ['value' => 'company', 'label' => 'Company account', 'icon' => $icon, 'note' => 'For a business.'],
        ]]);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//fieldset[contains(@class,"cl-ui-picture-choice--cards")]')->length, 'All cards: the card grid.');
        self::assertSame(2, $xpath->query('//label[contains(@class,"cl-ui-picture-choice-item--icon")]/input[@type="radio" and @name="account_type"]')->length);
        self::assertSame(2, $xpath->query('//label/span[@class="cl-ui-picture-choice-icon" and @aria-hidden="true"]/*[local-name()="svg"]')->length, 'The icon is decoration: the title names the option.');
        self::assertSame(0, $xpath->query('//img')->length);
        self::assertSame(['Individual learner', 'For yourself.'], [trim((string) $xpath->query('//*[@class="cl-ui-picture-choice-label"]')->item(0)?->textContent), trim((string) $xpath->query('//*[@class="cl-ui-picture-choice-note"]')->item(0)?->textContent)]);

        $ui = new PlatformUi();
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"/>',
            '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.com/a.png"/></svg>', '<p>not a picture</p>'] as $bad) {
            try {
                $ui->properties('form.picture-choice', ['name' => 'type', 'legend' => 'Type', 'items' => [['value' => 'a', 'label' => 'A', 'icon' => $bad]]]);
                self::fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage());
            }
        }
        try {
            $ui->properties('form.picture-choice', ['name' => 'type', 'legend' => 'Type', 'items' => [['value' => 'a', 'label' => 'A', 'image' => '/a.png', 'icon' => $icon]]]);
            self::fail('A picture and an icon at once.');
        } catch (\InvalidArgumentException $refused) {
            self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage());
        }
    }

    /** columns 4 (owner, 2026-10-20, the catalogue's categories): at most four cards to a row. */
    public function testFourColumnsIsAVariantAndNothingElseIs(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg>';
        $items = [['value' => 1, 'label' => 'Finance', 'icon' => $icon, 'note' => 'Keeping the books.']];
        $xpath = self::xpath(ComponentHarness::render('form.picture-choice', ['name' => 'category', 'legend' => 'Categories', 'items' => $items, 'size' => 'small', 'columns' => 4]));
        self::assertSame(1, $xpath->query('//fieldset[contains(@class,"cl-ui-picture-choice--four") and contains(@class,"cl-ui-picture-choice--small")]')->length);
        self::assertSame(0, self::xpath(ComponentHarness::render('form.picture-choice', ['name' => 'category', 'legend' => 'Categories', 'items' => $items]))->query('//fieldset[contains(@class,"cl-ui-picture-choice--four")]')->length);
        $this->expectException(\InvalidArgumentException::class);
        (new PlatformUi())->properties('form.picture-choice', ['name' => 'category', 'legend' => 'Categories', 'items' => $items, 'columns' => 3]);
    }

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return new \DOMXPath($document);
    }
}
