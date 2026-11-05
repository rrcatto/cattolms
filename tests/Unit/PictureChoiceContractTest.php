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
     * Drawn slides (owner, 2026-10-30: "I have to be able to see what each layout design looks like"): an option may be
     * a slide as the presentation player draws it, sent with a form elsewhere on the page when form is given.
     */
    public function testAnOptionMayBeADrawnSlideSentWithAnotherForm(): void
    {
        $drawing = (new \CattoLearning\Course\Catwalk\DeckRenderer())->thumbnail(['layout' => 'statement', 'title' => 'Idea', 'text' => 'Feed the soil.'], 'light', 'medium');
        $html = ComponentHarness::render('form.picture-choice', ['name' => 'slide_layout', 'legend' => 'One idea', 'selected' => 'statement', 'form' => 'slide-form', 'items' => [
            ['value' => 'statement', 'label' => 'Statement', 'drawing' => $drawing, 'note' => 'One big idea or a quotation'],
        ]]);
        $xpath = self::xpath($html);
        self::assertSame(1, $xpath->query('//label[contains(@class,"cl-ui-picture-choice-item--drawing")]/input[@type="radio" and @name="slide_layout" and @form="slide-form" and @checked]')->length);
        self::assertSame(1, $xpath->query('//span[@class="cl-ui-picture-choice-drawing"]//div[contains(@class,"cw-deck") and @inert and @data-cw-manual]//p[@class="cw-big"]')->length, 'The slide is drawn, never played.');

        $ui = new PlatformUi();
        foreach (['<div class="cl-slide-thumb cl-slide-thumb--small"><script>x()</script></div>', '<p>not a slide</p>', str_replace('<p class="cw-big">', '<p class="cw-big" onclick="x()">', $drawing)] as $bad) {
            try {
                $ui->properties('form.picture-choice', ['name' => 'layout', 'legend' => 'Layout', 'items' => [['value' => 'a', 'label' => 'A', 'drawing' => $bad]]]);
                self::fail('Accepted ' . $bad);
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

    /**
     * Feature card checkboxes (owner, 2026-11-01: "Feature Card Checkbox Selectors each with their own svg icon, title and
     * short description"): every card a native checkbox named by its value, sent as 1 when ticked.
     */
    public function testMultipleMakesEachCardACheckboxNamedByItsValue(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg>';
        $items = [
            ['value' => 'show_outline', 'label' => 'Show outline', 'icon' => $icon, 'note' => 'List its parts.'],
            ['value' => 'numbered', 'label' => 'Numbered module', 'icon' => $icon, 'note' => 'Module 1, Module 2.'],
            ['value' => 'public_preview', 'label' => 'Public preview', 'icon' => $icon, 'note' => 'In the free preview.'],
        ];
        $xpath = self::xpath(ComponentHarness::render('form.picture-choice', ['multiple' => true, 'legend' => 'Section options', 'size' => 'small', 'selected' => ['numbered', 'public_preview'], 'items' => $items]));
        self::assertSame(1, $xpath->query('//fieldset[contains(@class,"cl-ui-picture-choice--multiple") and contains(@class,"cl-ui-picture-choice--cards")]')->length);
        self::assertSame(['show_outline', 'numbered', 'public_preview'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('name'), iterator_to_array($xpath->query('//label/input[@type="checkbox" and @value="1"]'))));
        self::assertSame(['numbered', 'public_preview'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('name'), iterator_to_array($xpath->query('//input[@checked]'))));
        self::assertSame(0, $xpath->query('//input[@type="radio"]')->length);

        $ui = new PlatformUi();
        foreach ([['selected' => 'numbered'], ['selected' => [1]], ['name' => 'flags', 'selected' => []], ['selected' => [], 'items' => [['value' => 'Bad name', 'label' => 'A', 'icon' => $icon]]], ['multiple' => 'yes', 'name' => 'a']] as $broken) {
            try {
                $ui->properties('form.picture-choice', $broken + ['multiple' => true, 'legend' => 'Section options', 'items' => $items]);
                self::fail('Accepted ' . json_encode($broken));
            } catch (\InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage());
            }
        }
    }

    /**
     * Every radio and checkbox a feature card (owner, 2026-11-05: "That is the default I already established for the radio
     * and checkbox controls!"): cards can share one field, a card can send what its field reads, be required or be greyed
     * out, and a card's title can stand for a hidden legend.
     */
    public function testCardsCanShareAFieldSendTheirOwnValueAndBeRequiredOrDisabled(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg>';
        $xpath = self::xpath(ComponentHarness::render('form.picture-choice', ['multiple' => true, 'list' => 'tag_ids', 'legend' => 'Tags', 'selected' => [2], 'items' => [
            ['value' => 1, 'label' => 'One', 'icon' => $icon], ['value' => 2, 'label' => 'Two', 'icon' => $icon], ['value' => 3, 'label' => 'Three', 'icon' => $icon, 'disabled' => true],
        ]]));
        self::assertSame([['tag_ids[]', '1'], ['tag_ids[]', '2'], ['tag_ids[]', '3']], array_map(static fn(\DOMElement $input): array => [$input->getAttribute('name'), $input->getAttribute('value')], iterator_to_array($xpath->query('//label/input[@type="checkbox"]'))));
        self::assertSame(['2'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('value'), iterator_to_array($xpath->query('//input[@checked]'))));
        self::assertSame(['3'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('value'), iterator_to_array($xpath->query('//input[@disabled]'))));
        self::assertSame(0, $xpath->query('//input[@required]')->length);
        self::assertSame(0, $xpath->query('//legend[contains(@class,"visually-hidden")]')->length);

        $html = ComponentHarness::render('form.picture-choice', ['multiple' => true, 'legend' => 'Terms', 'legend_hidden' => true, 'tick_value' => 'yes', 'required' => true, 'selected' => [], 'items' => [['value' => 'accept_terms', 'label' => 'I accept', 'icon' => $icon]]]);
        self::assertStringContainsString('<input type="checkbox" name="accept_terms" value="yes" required>', $html);
        $xpath = self::xpath($html);
        self::assertSame('Terms', trim((string) $xpath->query('//legend[contains(@class,"visually-hidden")]')->item(0)?->textContent), 'A hidden legend still names the choice for a screen reader.');
        self::assertSame(1, $xpath->query('//fieldset[contains(@class,"cl-ui-picture-choice--single")]')->length, 'A lone card takes the whole row.');
        self::assertSame(1, self::xpath(ComponentHarness::render('form.picture-choice', ['multiple' => true, 'legend' => 'Lots', 'columns' => 1, 'selected' => [], 'items' => [['value' => 'a', 'label' => 'A', 'icon' => $icon], ['value' => 'b', 'label' => 'B', 'icon' => $icon]]]))->query('//fieldset[contains(@class,"cl-ui-picture-choice--one") and not(contains(@class,"--single"))]')->length);

        $radios = self::xpath(ComponentHarness::render('form.picture-choice', ['name' => 'type', 'legend' => 'Type', 'required' => true, 'items' => [['value' => 'a', 'label' => 'A', 'icon' => $icon, 'disabled' => true], ['value' => 'b', 'label' => 'B', 'icon' => $icon]]]));
        self::assertSame(2, $radios->query('//input[@type="radio" and @required]')->length);
        self::assertSame(['a'], array_map(static fn(\DOMElement $input): string => $input->getAttribute('value'), iterator_to_array($radios->query('//input[@disabled]'))));
        self::assertSame(1, self::xpath(ComponentHarness::render('form.picture-choice', ['multiple' => true, 'legend' => 'Flag', 'selected' => ['slide[build]'], 'items' => [['value' => 'slide[build]', 'label' => 'Build', 'icon' => $icon]]]))->query('//input[@name="slide[build]" and @checked]')->length);

        $ui = new PlatformUi();
        $card = ['value' => 'a', 'label' => 'A', 'icon' => $icon];
        foreach ([
            'A list without checkboxes' => ['name' => 'type', 'list' => 'tag_ids', 'items' => [$card]],
            'A list named badly' => ['multiple' => true, 'list' => 'Bad name', 'selected' => [], 'items' => [$card]],
            'A list sending a fixed value' => ['multiple' => true, 'list' => 'tag_ids', 'tick_value' => 'yes', 'selected' => [], 'items' => [$card]],
            'A value that is not a word' => ['multiple' => true, 'tick_value' => '"><script>', 'selected' => [], 'items' => [$card]],
            'Required that is not true or false' => ['multiple' => true, 'required' => 'yes', 'selected' => [], 'items' => [$card]],
            'A hidden legend that is not true or false' => ['multiple' => true, 'legend_hidden' => 1, 'selected' => [], 'items' => [$card]],
            'Disabled that is not true or false' => ['multiple' => true, 'selected' => [], 'items' => [$card + ['disabled' => 'yes']]],
            'A number as a named card' => ['multiple' => true, 'selected' => [], 'items' => [['value' => 1, 'label' => 'A', 'icon' => $icon]]],
        ] as $what => $broken) {
            try {
                $ui->properties('form.picture-choice', $broken + ['legend' => 'Choice']);
                self::fail('Accepted: ' . $what);
            } catch (\InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage(), $what);
            }
        }
    }

    /** Words that need markup go in a block when the component is embedded: a card's links, a price with its was price. */
    public function testACardsTitleAndNoteCanHoldMarkupWhenEmbedded(): void
    {
        $source = '{% embed ui_template(\'form.picture-choice\') with {props: ui_props(\'form.picture-choice\', {multiple: true, legend: \'Terms\', selected: [], items: [{value: \'accept\', label: \'Plain\', icon: icon, note: \'Plain note\'}]})} %}{% block item_label %}I accept <a href="/terms">the terms</a> of {{ item.value }}{% endblock %}{% block item_note %}<em>Marked</em>{% endblock %}{% endembed %}';
        $xpath = self::xpath(\CattoLearning\Tests\Support\RenderHarness::renderSource($source, ['icon' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/></svg>']));
        self::assertSame(1, $xpath->query('//label//*[@class="cl-ui-picture-choice-label"]/a[@href="/terms"]')->length);
        self::assertStringContainsString('of accept', (string) $xpath->query('//*[@class="cl-ui-picture-choice-label"]')->item(0)?->textContent, 'A block sees the card it is drawing.');
        self::assertSame(1, $xpath->query('//label//em')->length);
    }

    /** Every icon a template asks the library for exists: a missing one stops the page, and many pages are drawn only for ADMIN. */
    public function testEveryIconATemplateNamesIsInTheLibrary(): void
    {
        $library = new \CattoLearning\View\Artwork\IconLibrary();
        $checked = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/resources/views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with((string) $file, '.twig')) continue;
            $source = (string) file_get_contents((string) $file);
            preg_match_all("/lucide\\('([a-z0-9-]+)'\\)/", $source, $named);
            // A map of icons beside the choice: {key: 'icon-name', ...} assigned to a variable ending in _icons.
            preg_match_all('/_icons = \{([^}]*)\}/', $source, $maps);
            $names = $named[1];
            foreach ($maps[1] as $map) {
                preg_match_all("/: '([a-z0-9-]+)'/", $map, $inMap);
                $names = array_merge($names, $inMap[1]);
            }
            foreach ($names as $name) {
                self::assertTrue($library->has($name), basename((string) $file) . ' names the icon ' . $name . ', which the library does not have.');
                $checked++;
            }
        }
        self::assertGreaterThan(100, $checked, 'The templates were read.');
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
