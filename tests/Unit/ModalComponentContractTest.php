<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the canonical ModalComponent presentation contract. */
final class ModalComponentContractTest extends TestCase
{
    public function testModalOwnsOneFooterAndAccessibleTitleWithWorkingGetFallback(): void
    {
        $html = ComponentHarness::render('overlay.modal', ['id' => 'example', 'title' => '<Example>', 'action' => '/example']);
        foreach (['role="dialog"', 'aria-labelledby="example-title"', 'id="example-title"', '&lt;Example&gt;', 'action="/example"', 'method="post"'] as $token) self::assertStringContainsString($token, $html);
        self::assertSame(1, substr_count($html, 'class="cl-ui-modal-foot"'));
        self::assertStringNotContainsString('cl-ui-form-actions', $html);
        self::assertStringNotContainsString(' hidden', $html, 'Without JavaScript the same forms must be reachable.');
    }
    public function testAccordionUsesNativeDisclosureAndOmittedEmptySummary(): void
    {
        $html = ComponentHarness::render('overlay.accordion-section', ['heading' => 'Settings', 'open' => true]);
        self::assertStringContainsString('<details', $html);
        self::assertStringContainsString(' open>', $html);
        self::assertStringNotContainsString('cl-ui-accordion-description', $html);
        self::assertStringNotContainsString('cl-ui-accordion-actions', $html);
    }
    public function testIconsDistinguishDecorationAndMeaning(): void
    {
        self::assertStringContainsString('aria-hidden="true"', ComponentHarness::render('icon', ['name' => 'nav-home']));
        self::assertStringContainsString('aria-label="Home"', ComponentHarness::render('icon', ['name' => 'nav-home', 'decorative' => false, 'label' => 'Home']));
        $this->expectException(\InvalidArgumentException::class);
        ComponentHarness::render('icon', ['name' => 'nav-home', 'decorative' => false]);
    }
}
