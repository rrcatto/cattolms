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

    public function testHelpUsesVisiblePublicLearningGuideSections(): void
    {
        $html = RenderHarness::render('pages/help', RenderHarness::hiveWith([]));
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        foreach (['find-a-course', 'buy-access', 'course-structure', 'learn', 'sign-in', 'answers'] as $id) {
            self::assertGreaterThanOrEqual(1, $xpath->query('//*[@id="' . $id . '"]')->length, 'Missing help section: ' . $id);
        }
        self::assertSame(0, $xpath->query('//details')->length, 'Public help should keep guidance visible.');
        self::assertStringContainsString('Find a course', $html);
        self::assertStringContainsString('Buy access', $html);
        self::assertStringContainsString('What a course contains', $html);
        self::assertStringContainsString('My Course Library', $html);
        self::assertStringContainsString('/courses', $html);
        self::assertStringContainsString('/contact', $html);
    }

    public function testHelpDoesNotExposeAdministrativeGuidance(): void
    {
        $html = strtolower(RenderHarness::render('pages/help', RenderHarness::hiveWith([])));
        foreach (['administration', 'administrator', 'company', 'course credits', 'theme package', 'roles & acl'] as $term) {
            self::assertStringNotContainsString($term, $html);
        }
    }
}
