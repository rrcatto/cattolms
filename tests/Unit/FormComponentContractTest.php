<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the canonical FormComponent presentation contract. */
final class FormComponentContractTest extends TestCase
{
    public function testFieldOwnsLabelHelpErrorAndFullWidth(): void
    {
        $html = ComponentHarness::render('form.field', ['label' => '<Name>', 'for' => 'sample', 'help' => 'Help & guidance', 'error' => '<Error>', 'required' => true, 'full_width' => true]);
        foreach (['for="sample"', 'cl-ui-field--full', '&lt;Name&gt;', 'id="sample-help"', 'id="sample-error"', 'role="alert"', 'cl-ui-required'] as $token) self::assertStringContainsString($token, $html);
        $plain = ComponentHarness::render('form.field', ['label' => 'Name']);
        self::assertStringNotContainsString('cl-ui-field-help', $plain);
        self::assertStringNotContainsString('cl-ui-field-error', $plain);
    }
    public function testEveryRegisteredComponentRejectsStructuralEscapeHatches(): void
    {
        $ui = new PlatformUi();
        foreach (array_keys(PlatformUi::COMPONENTS) as $name) {
            foreach (['class', 'wrapper_class', 'style', 'css', 'container_class', 'html'] as $key) {
                try { $ui->properties($name, [$key => '<div>escape</div>']); self::fail($name . ' accepted ' . $key); }
                catch (\InvalidArgumentException $exception) { self::assertStringContainsString('Unknown UI property', $exception->getMessage()); }
            }
        }
    }
    public function testSemanticValuesAreBounded(): void
    {
        foreach (['feedback.badge' => ['label' => 'Example', 'tone' => 'good'], 'layout.surface' => ['variant' => 'custom'], 'form.grid' => ['columns' => 8], 'action.link' => ['href' => '/', 'label' => 'Go', 'size' => 'giant']] as $name => $properties) {
            try { (new PlatformUi())->properties($name, $properties); self::fail('Invalid variant accepted'); }
            catch (\InvalidArgumentException $exception) { self::assertStringContainsString('Invalid semantic UI value', $exception->getMessage()); }
        }
    }
    public function testRenderedMarkupCannotBypassTextEscaping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PlatformUi())->properties('action.button', ['label' => new \Twig\Markup('<b>Unsafe</b>', 'UTF-8')]);
    }

    public function testActionVariantsUseRealLinksAndNativeButtons(): void
    {
        foreach (['primary', 'secondary', 'quiet', 'danger'] as $variant) {
            $html = ComponentHarness::render('action.link', ['label' => '<Go>', 'href' => '/courses?q=a&b=1', 'variant' => $variant]);
            self::assertStringContainsString('href="/courses?q=a&amp;b=1"', $html);
            self::assertStringContainsString('cl-ui-action--' . $variant, $html);
            self::assertStringContainsString('&lt;Go&gt;', $html);
        }
        self::assertStringContainsString('type="submit"', ComponentHarness::render('action.button', ['label' => 'Save']));
    }

    public function testAButtonPostsItsFormElsewhereOnlyOnThisSiteAndIntoANamedFrame(): void
    {
        $html = ComponentHarness::render('action.button', ['label' => 'Update preview', 'type' => 'submit', 'form_action' => '/admin/certificates/designs/preview.pdf', 'form_target' => 'certificate-preview', 'skip_validation' => true]);
        foreach (['formaction="/admin/certificates/designs/preview.pdf"', 'formtarget="certificate-preview"', 'formnovalidate'] as $attribute) {
            self::assertStringContainsString($attribute, $html);
        }
        foreach ([['form_action' => 'https://example.com/steal'], ['form_action' => '//example.com/x'], ['form_action' => 'javascript:alert(1)'], ['form_target' => '_top'], ['form_target' => 'Frame name']] as $broken) {
            try { (new PlatformUi())->properties('action.button', $broken + ['label' => 'Go']); self::fail('Accepted ' . json_encode($broken)); }
            catch (\InvalidArgumentException $refused) { self::assertStringContainsString('only to a path on this site', $refused->getMessage()); }
        }
    }
}
