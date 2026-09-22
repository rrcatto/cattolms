<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use CattoLearning\View\Ui\UiOwnershipAudit;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class UiRemediationContractTest extends TestCase
{
    private static function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return new DOMXPath($dom);
    }

    public function testFieldControlRelationshipsMatchRenderedHelpAndErrors(): void
    {
        $source = <<<'TWIG'
{% embed ui_template('form.field') with {props: ui_props('form.field', field)} %}
{% block control %}<input id="{{ props.for }}" {{ props.required ? 'required' : '' }} {{ ui_field_attrs(props) }}>{% endblock %}
{% endembed %}
TWIG;
        foreach ([['', ''], ['Guidance', ''], ['', 'Required'], ['Guidance', 'Required']] as [$help, $error]) {
            $id = 'field-"<&';
            $html = RenderHarness::renderSource($source, ['field' => ['label' => 'Name', 'for' => $id, 'help' => $help, 'error' => $error, 'required' => true]]);
            $dom = self::dom($html);
            $expected = array_values(array_filter([$help !== '' ? $id . '-help' : '', $error !== '' ? $id . '-error' : '']));
            self::assertSame(implode(' ', $expected), $dom->evaluate('string(//input/@aria-describedby)'));
            self::assertSame($error !== '' ? 'true' : '', $dom->evaluate('string(//input/@aria-invalid)'));
            self::assertSame($id, $dom->evaluate('string(//label/@for)'));
            self::assertSame(1.0, $dom->evaluate('count(//input[@required])'));
            $ids = [];
            foreach ($dom->query('//*[@id]') ?: [] as $element) {
                if ($element instanceof \DOMElement) $ids[] = $element->getAttribute('id');
            }
            self::assertSame(array_merge([$id], $expected), $ids);
        }
        $slot = str_replace('{% block control %}', '{% block help_content %}Read <a href="/help">Help</a>.{% endblock %}{% block control %}', $source);
        $dom = self::dom(RenderHarness::renderSource($slot, ['field' => ['label' => 'Name', 'for' => 'slot']]));
        self::assertSame('slot-help', $dom->evaluate('string(//input/@aria-describedby)'));
        self::assertSame(1.0, $dom->evaluate('count(//*[@id="slot-help"]/a[@href="/help"])'));
    }

    public function testFieldHelperRejectsRawAttributes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PlatformUi())->fieldAttributes(['label' => 'Name', 'for' => 'name', 'attributes' => ['onclick' => 'bad()']]);
    }

    public function testActionElementSpecificPropertiesAreRejected(): void
    {
        foreach (['action.link' => ['disabled', 'form', 'name', 'value', 'close_modal', 'stimulus_action', 'type'], 'action.button' => ['href', 'new_window', 'rel', 'navigation_key']] as $component => $properties) {
            foreach ($properties as $property) {
                try {
                    (new PlatformUi())->properties($component, ['label' => 'Test', $property => true]);
                    self::fail($component . ' accepted ' . $property);
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString($component . ': ' . $property, $error->getMessage());
                }
            }
        }
    }

    public function testActionLinksRenderIconsOnlyWhenTheCallerRequestsOne(): void
    {
        $plain = RenderHarness::renderSource("{{ ui('action.link', {label: 'Next →', href: '/next'}) }}");
        self::assertStringNotContainsString('<svg', $plain);
        $icon = RenderHarness::renderSource("{{ ui('action.link', {label: 'Create', href: '/create', icon: 'action-add'}) }}");
        self::assertStringContainsString('<svg', $icon);
    }

    public function testQuestionPrototypesAndExistingQuestionsShareCanonicalControls(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['pages/admin-course-item-form'] as $caller) {
            self::assertStringContainsString("include '@platform/partials/question-editor.html.twig'", (string) file_get_contents($root . '/resources/views/' . $caller . '.html.twig'));
        }
        $javascript = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($root . '/public_html/js/question-editor.js'));
        self::assertDoesNotMatchRegularExpression('/insertAdjacentHTML|innerHTML\s*=|createElement\s*\(/', $javascript);

        foreach ([false, true] as $diagnostic) {
            $html = RenderHarness::render('partials/question-editor', ['diagnostic' => $diagnostic, 'editor_questions' => [['question_html' => '<Saved>', 'practice_eligible' => false, 'graded_eligible' => false, 'remediation_item_keys' => ['m1'], 'options' => [['option_html' => 'First', 'is_correct' => false], ['option_html' => 'Second', 'is_correct' => true]]]]]);
            $dom = self::dom($html);
            self::assertSame(1.0, $dom->evaluate('count(//*[@id="question-editor"]/*[@data-question])'));
            self::assertSame(1.0, $dom->evaluate('count(//template[@id="question-editor-question-template"]//*[@data-question])'));
            self::assertSame(1.0, $dom->evaluate('count(//template[@id="question-editor-option-template"]//*[@data-option])'));
            self::assertSame(1.0, $dom->evaluate('count(//*[@id="question-editor"]//input[@type="radio" and @value="1" and @checked])'));
            self::assertSame('<Saved>', $dom->evaluate('string(//*[@id="question-0-question_html"])'));
            self::assertSame(0.0, $dom->evaluate('count(//*[@id="question-editor"]//input[@type="checkbox" and @checked])'));
            self::assertSame($diagnostic ? 1.0 : 0.0, $dom->evaluate('count(//*[@id="question-editor"]//input[@name="remediation_item_keys[0]" and @value="m1"])'));
            self::assertSame(0.0, $dom->evaluate('count(//*[@data-question]//button[not(contains(@class,"cl-ui-action"))])'));
            self::assertStringContainsString('question-__q__-option-__o__', $html);
        }
    }

    public function testUnsavedEditorButtonsCanSkipNativeValidationWithoutSaving(): void
    {
        $html = RenderHarness::renderSource("{{ ui('action.button', {label:'Add question', type:'submit', name:'question_action', value:'add-question', skip_validation:true}) }}");
        self::assertSame(1.0, self::dom($html)->evaluate('count(//button[@formnovalidate and @name="question_action"])'));
        $normal = RenderHarness::renderSource("{{ ui('action.button', {label:'Save', type:'submit'}) }}");
        self::assertSame(0.0, self::dom($normal)->evaluate('count(//button[@formnovalidate])'));
    }

    public function testJavascriptOwnershipGuardDetectsGeneratedControlsWithoutBanningSelectors(): void
    {
        foreach (["node.innerHTML = '<button class=\"btn btn-primary\">Go</button>';", 'node.insertAdjacentHTML("beforeend", `<section class="card"><div class="card-body"></div></section>`);', "node.className = 'cl-ui-action';", "node.classList.add('row-actions');", "node.setAttribute('class', 'badge');"] as $source) {
            self::assertNotSame([], UiOwnershipAudit::javascript($source, 'example.js'), $source);
        }
        self::assertSame([], UiOwnershipAudit::javascript('// node.innerHTML = `<div class="card">`;' . "\n/* card */ document.querySelector('.card'); node.innerHTML = '<strong>Text</strong>';", 'safe.js'));
        self::assertNotSame([], UiOwnershipAudit::stimulusActions('<input data-action="change-{% if props.help %} aria-describedby="bad"{% endif %}>profile-image#choose">', 'profile.twig'));
        self::assertNotSame([], UiOwnershipAudit::themeGeometry('@media(max-width:700px){.cl-ui-row-actions{flex-wrap:wrap}}', 'theme.css'));
        self::assertSame([], UiOwnershipAudit::themeGeometry('.cl-ui-action{color:red;border-radius:5px}', 'theme.css'));
    }

    public function testCurrentJavascriptAndThemesRespectOwnership(): void
    {
        $root = dirname(__DIR__, 2);
        $errors = [];
        foreach (['public_html/js', 'assets/controllers'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'js') $errors = array_merge($errors, UiOwnershipAudit::javascript((string) file_get_contents($file->getPathname()), $file->getPathname()));
            }
        }
        foreach (glob($root . '/themes/*/public/css/*.css') ?: [] as $file) $errors = array_merge($errors, UiOwnershipAudit::themeGeometry((string) file_get_contents($file), $file));
        self::assertSame([], $errors);
    }
}
