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

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return new \DOMXPath($document);
    }
}
