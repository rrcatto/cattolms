<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\View\Ui\ProjectComponents;
use PHPUnit\Framework\TestCase;

/** form.picture-choice is CattoPHP's (CattoPHP Phase 3, step 1), with its own tests there; these check what is cattolms's own in it: a drawn slide as a card (SlideDrawing) and the library icons its templates name. */
final class PictureChoiceContractTest extends TestCase
{
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

        $ui = ProjectComponents::registry();
        foreach (['<div class="cl-slide-thumb cl-slide-thumb--small"><script>x()</script></div>', '<p>not a slide</p>', str_replace('<p class="cw-big">', '<p class="cw-big" onclick="x()">', $drawing)] as $bad) {
            try {
                $ui->properties('form.picture-choice', ['name' => 'layout', 'legend' => 'Layout', 'items' => [['value' => 'a', 'label' => 'A', 'drawing' => $bad]]]);
                self::fail('Accepted ' . $bad);
            } catch (\InvalidArgumentException $refused) {
                self::assertMatchesRegularExpression('/picture choice/', $refused->getMessage());
            }
        }
    }

    /** Every icon a template asks the library for exists: a missing one stops the page, and many pages are drawn only for ADMIN. */
    public function testEveryIconATemplateNamesIsInTheLibrary(): void
    {
        $library = new \CattoPHP\Ui\IconLibrary(\CattoLearning\View\Artwork\CourseIcons::SUBJECTS, \CattoLearning\View\Artwork\CourseIcons::NAME_STOP_WORDS);
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

    private static function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        return new \DOMXPath($document);
    }
}
