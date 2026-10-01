<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

/**
 * Twig never interprets tags inside a string, and joining rendered component markup with ~ turns
 * it into text that the receiving component escapes. Both printed raw template code and HTML on
 * the public course page's Course access options.
 */
final class TemplateStringContractTest extends TestCase
{
    public function testNoTemplateQuotesTwigTagsOrConcatenatesRenderedComponents(): void
    {
        $root = RenderHarness::root();
        $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/resources/views')), '/\.twig$/');
        $themes = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/themes')), '/\.twig$/');
        $checked = 0;
        foreach ([$files, $themes] as $iterator) {
            foreach ($iterator as $file) {
                $source = (string) file_get_contents($file->getPathname());
                $path = substr($file->getPathname(), strlen($root) + 1);
                self::assertDoesNotMatchRegularExpression("/'\\s*\\{%\\s*(?:if|endif|else|for|endfor)\\b/", $source, $path . ' puts a Twig tag inside a string; it would print as text.');
                self::assertDoesNotMatchRegularExpression('/~\\s*\\(?\\s*ui\\(/', $source, $path . ' joins rendered component markup into a string; the receiving component escapes it.');
                $checked++;
            }
        }
        self::assertGreaterThan(100, $checked);
    }
}
