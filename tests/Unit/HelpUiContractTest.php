<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

final class HelpUiContractTest extends TestCase
{
    public function testHelpIsPublicAndDoesNotRequireAnAuthenticatedUser(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/Controller/HelpController.php');
        self::assertStringContainsString("Route('/help'", $source);
        self::assertStringNotContainsString('requireUser()', $source);
    }

    /**
     * The guide's topics are closed accordions, each with its icon and its category, under a header picture
     * with the title over it, beside a big link to the FAQ (owner, 2026-10-20: "use <details> <summary> tags
     * styled as accordions, in the same way the FAQ page is built"). It replaced the always-open sections.
     */
    public function testHelpOpensEachTopicAsAnAccordionUnderItsPicture(): void
    {
        $html = RenderHarness::render('pages/help', RenderHarness::hiveWith([]));
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        // The harness draws the page body more than once, so each check asks for at least one.
        self::assertSame(0, $xpath->query('//details[@open]')->length, 'Every topic starts closed.');
        foreach (['find-a-course', 'buy-access', 'course-structure', 'learn', 'sign-in', 'answers'] as $id) {
            self::assertGreaterThanOrEqual(1, $xpath->query('//details[@id="' . $id . '" and not(@open)]/summary[contains(@class,"cl-ui-accordion-summary--labelled")]')->length, 'The topic ' . $id . ' is a closed accordion.');
            self::assertGreaterThanOrEqual(1, $xpath->query('//details[@id="' . $id . '"]/summary/span[@class="cl-ui-accordion-icon"]/*[local-name()="svg"]')->length, 'The topic ' . $id . ' has its icon.');
            self::assertGreaterThanOrEqual(1, $xpath->query('//details[@id="' . $id . '"]/summary//span[@class="cl-ui-accordion-eyebrow"]')->length, 'The topic ' . $id . ' has its category.');
        }
        self::assertGreaterThanOrEqual(1, $xpath->query('//section[contains(@class,"cl-page-head--picture")]//div[@class="cl-picture-head-art"]/*[local-name()="svg"]')->length, 'The header has its picture.');
        self::assertGreaterThanOrEqual(1, $xpath->query('//div[contains(@class,"cl-picture-head-text")]/h1')->length, 'The title sits over it.');
        self::assertGreaterThanOrEqual(1, $xpath->query('//a[@class="cl-help-faq" and @href="/faq"]/abbr[normalize-space()="FAQ"]')->length, 'A big link to the FAQ.');
        foreach (['Find a course', 'Buy access', 'What a course contains', 'My Courses', '/courses', '/contact'] as $text) self::assertStringContainsString($text, $html);
        self::assertStringNotContainsString('My Course Library', $html, 'The account calls it My Courses.');
    }

    public function testHelpDoesNotExposeAdministrativeGuidance(): void
    {
        $html = strtolower(RenderHarness::render('pages/help', RenderHarness::hiveWith([])));
        foreach (['administration', 'administrator', 'company', 'course credits', 'theme package', 'roles & acl'] as $term) {
            self::assertStringNotContainsString($term, $html);
        }
    }
}
