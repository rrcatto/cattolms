<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

/** Notice headings and prose emphasis have different semantics and styling hooks. */
final class NoticeMarkupContractTest extends TestCase
{
    public function testHeadingIsDistinctFromInlineEmphasisInRealCanonicalNotice(): void
    {
        $html = RenderHarness::renderSource(<<<'TWIG'
{% embed ui_template('feedback.notice') with {props: ui_props('feedback.notice', {heading: 'Course access', tone: 'info'})} %}
{% block body %}<p>You can <strong>start learning</strong> now.</p>{% endblock %}
{% endembed %}
TWIG);
        self::assertStringContainsString('<strong class="cl-ui-notice-heading">Course access</strong>', $html);
        self::assertStringContainsString('<p>You can <strong>start learning</strong> now.</p>', $html);
        foreach (glob(dirname(__DIR__, 2) . '/themes/*/public/css/*.css') ?: [] as $file) {
            $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($file));
            self::assertDoesNotMatchRegularExpression('/\.cl-ui-notice\s+strong\b/', $css, $file . ' must target the explicit heading hook, not prose emphasis.');
        }
    }
}
