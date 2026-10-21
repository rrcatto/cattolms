<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\CanonicalPageFixture;
use Dom\HTMLDocument;
use Dom\XPath;
use PHPUnit\Framework\TestCase;

/** Verifies shared page landmarks and native navigation across bundled theme shells. */
final class CanonicalPageConstructionTest extends TestCase
{
    public function testEveryThemeUsesCanonicalLandmarksInBothAuthenticationStates(): void
    {
        foreach (['radiant-learning', 'light-default', 'gilded-noir', 'factory-reset', 'factory-reset-sidebar', 'saharan-sunset'] as $slug) {
            $structures = [];
            foreach ([false, true] as $authenticated) {
                $html = CanonicalPageFixture::render($slug, $authenticated);
                $dom = HTMLDocument::createFromString($html, \Dom\HTML_NO_DEFAULT_NS);
                $xpath = new XPath($dom);
                $cls = static fn(string $class): string => '[contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")]';
                $frame = '//div' . $cls('cl-page-frame');
                foreach (['app-shell', 'public-shell', 'rl-shell', 'ld-shell', 'gn-shell', 'fs-shell', 'gn-main', 'ld-main', 'public-main', 'gn-footer', 'fs-footer', 'gn-identity', 'nav-link', 'nav-icon', 'flash-stack'] as $obsolete) {
                    self::assertSame(0, $xpath->query('//*' . $cls($obsolete))->length, $slug . ': obsolete shared class ' . $obsolete);
                }
                self::assertSame(1, $xpath->query('//body' . $cls('cl-body') . '/div' . $cls('cl-shell') . '/div' . $cls('cl-page') . '/div' . $cls('cl-page-frame'))->length, $slug);
                self::assertSame(1, $xpath->query('//main')->length, $slug);
                self::assertSame(1, $xpath->query('//a' . $cls('cl-skip-link') . '[@href="#cl-main"]')->length, $slug);
                self::assertSame(1, $xpath->query('//button' . $cls('cl-nav-toggle') . '[@type="button"][@aria-controls="cl-primary-navigation"]')->length, $slug);
                self::assertSame(1, $xpath->query($frame . '/main' . $cls('cl-main') . '[@id="cl-main"]')->length, $slug);
                self::assertSame(1, $xpath->query($frame . '/main/following-sibling::*[1][self::footer]' . $cls('cl-footer'))->length, $slug);
                self::assertSame(1, $xpath->query('//section' . $cls('cl-page-context') . '/div' . $cls('cl-page-context-inner'))->length, $slug);
                self::assertSame(1, $xpath->query('//section' . $cls('cl-page-head') . '/div' . $cls('cl-page-head-inner') . '/div' . $cls('cl-page-head-content'))->length, $slug);
                self::assertSame(1, $xpath->query('//section' . $cls('cl-page-section'))->length, $slug);
                self::assertSame(1, $xpath->query('//div' . $cls('cl-page-head-actions') . '/a[@href="/courses"]')->length, $slug);
                self::assertSame(1, $xpath->query('//div' . $cls('cl-flash-stack') . '//*[@data-flash-message]')->length, $slug);
                self::assertSame(1, $xpath->query('//footer/div' . $cls('cl-footer-inner'))->length, $slug);
                self::assertSame(1, $xpath->query('//footer//nav' . $cls('cl-footer-nav') . '//a[@href="/help"]')->length, $slug);
                self::assertSame(1, $xpath->query('//nav[@id="cl-primary-navigation"]' . $cls('cl-navigation'))->length, $slug);
                $landmark = str_starts_with($slug, 'factory-reset') ? 'aside' . $cls('cl-sidebar') : 'header' . $cls('cl-header');
                self::assertSame(1, $xpath->query('//div' . $cls('cl-shell') . '/' . $landmark)->length, $slug);
                self::assertSame($authenticated ? 1 : 0, $xpath->query('//form[@action="/logout"][@method="post"]/input[@name="csrf"][@value="test-csrf"]')->length, $slug);
                self::assertSame($authenticated ? 1 : 0, $xpath->query('//a[@href="/account/emails"]' . $cls('cl-nav-sublink'))->length, $slug);
                self::assertSame($authenticated ? 0 : 1, $xpath->query('//nav[@id="cl-primary-navigation"]/a[@href="/login"]')->length, $slug);
                self::assertSame(0, $xpath->query('//a[@href="/logout"]')->length, 'Logout must retain POST and CSRF.');
                if ($slug === 'gilded-noir') {
                    self::assertSame($authenticated ? 1 : 0, $xpath->query('//header//div' . $cls('cl-identity') . $cls('cl-identity-compact'))->length);
                    self::assertSame(0, $xpath->query('//main//section' . $cls('cl-identity-head'))->length);
                    self::assertSame(3, $xpath->query('//footer//section' . $cls('cl-footer-section'))->length);
                    foreach (['gn-footer-art', 'gn-footer-veil', 'gn-footnote', 'gn-canvas', 'gn-ribbon'] as $decoration) self::assertSame(1, $xpath->query('//*' . $cls($decoration))->length);
                } else {
                    self::assertSame(1, $xpath->query('//section' . $cls('cl-identity') . $cls('cl-identity-head'))->length, $slug);
                }
                $structures[] = array_map(static fn($node): string => $node->nodeName . ':' . $node->parentNode?->nodeName, iterator_to_array($xpath->query('//div' . $cls('cl-shell') . ' | //div' . $cls('cl-page') . ' | ' . $frame . ' | //main | //footer')));
            }
            self::assertSame($structures[0], $structures[1], $slug . ' must not switch shell structure with authentication.');
        }
    }

    public function testThemesDelegateNavigationAndRejectObsoleteSharedClassNames(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (glob($root . '/themes/*/base.html.twig') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            self::assertStringContainsString("@platform/partials/navigation.html.twig", $source);
            self::assertDoesNotMatchRegularExpression('/class="[^"\n]*(?:\b(?:public-shell|app-shell|gn-shell|rl-shell|ld-shell|fs-shell|gn-footer|fs-footer|gn-identity)\b)/', $source);
            self::assertStringNotContainsString('href="/login"', $source, 'Authentication navigation belongs to the core model.');
        }
    }

    /**
     * Saharan Sunset's sign-in panel (owner, 2026-10-20: the serpent "will put off some people … make them
     * different for the Login vs Register pages"): a doorway for signing in, a dawn for registering, each
     * with its own line, chosen by page.name.
     */
    public function testSaharanSunsetShowsOnePictureForSigningInAndAnotherForRegistering(): void
    {
        $expect = [
            'login' => ['ss-auth--sign-in', 'Sign in on any device'],
            'login-sent' => ['ss-auth--sign-in', 'Sign in on any device'],
            'register' => ['ss-auth--register', 'Create your account once.'],
            'company-register' => ['ss-auth--register', 'Register your company once'],
        ];
        foreach ($expect as $page => [$class, $line]) {
            $html = CanonicalPageFixture::render('saharan-sunset', $page === 'company-register', $page);
            self::assertStringContainsString('class="cl-page-family ss-auth ' . $class . '"', $html, $page);
            self::assertStringContainsString($line, $html, $page);
        }
        $theme = dirname(__DIR__, 2) . '/themes/saharan-sunset';
        $css = (string) file_get_contents($theme . '/public/css/theme.css');
        self::assertStringContainsString('url("../img/auth-sign-in.svg")', $css);
        self::assertStringContainsString('.ss-auth--register .ss-auth-art { background-image: url("../img/auth-register.svg")', $css);
        self::assertStringNotContainsString('serpent.jpg', $css);
        self::assertFileExists($theme . '/public/img/auth-sign-in.svg');
        self::assertFileExists($theme . '/public/img/auth-register.svg');
        self::assertFileDoesNotExist($theme . '/public/img/footer-serpent.jpg');
        self::assertStringContainsString("'name' => \$page,", (string) file_get_contents(dirname(__DIR__, 2) . '/src/View/ThemeRenderer.php'), 'Themes receive page.name.');
    }
}
