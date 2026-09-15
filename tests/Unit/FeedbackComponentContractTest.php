<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;
use CattoLearning\Tests\Support\ComponentHarness;
use CattoLearning\Tests\Support\RenderHarness;
use CattoLearning\View\Ui\PlatformUi;
use PHPUnit\Framework\TestCase;

/** Guards the canonical FeedbackComponent presentation contract. */
final class FeedbackComponentContractTest extends TestCase
{
    public function testAllSemanticTonesEscapeLabels(): void
    {
        foreach (['neutral', 'info', 'success', 'warning', 'danger', 'permanent'] as $tone) {
            $html = ComponentHarness::render('feedback.badge', ['label' => '<b>Unsafe</b>', 'tone' => $tone]);
            self::assertStringContainsString('cl-ui-badge--' . $tone, $html);
            self::assertStringContainsString('&lt;b&gt;', $html);
            self::assertStringNotContainsString('<b>', $html);
        }
        foreach (['info', 'success', 'warning', 'danger'] as $tone) {
            $html = ComponentHarness::render('feedback.notice', ['tone' => $tone, 'heading' => '<strong>Title</strong>']);
            self::assertStringContainsString('cl-ui-notice--' . $tone, $html);
            self::assertStringContainsString('&lt;strong&gt;', $html);
        }
    }
    public function testEmptyFeedbackOmitsEmptyHeadings(): void
    {
        self::assertStringNotContainsString('<h3', ComponentHarness::render('feedback.empty', ['summary' => 'Nothing yet']));
        self::assertStringNotContainsString('<strong', ComponentHarness::render('feedback.notice'));
    }
    public function testProgressClampsValuesAndCarriesItsRangeAndLabel(): void
    {
        foreach ([[-10, 0], [45, 45], [200, 100]] as [$input, $expected]) {
            $html = ComponentHarness::render('feedback.progress', ['value' => $input, 'label' => 'Course progress']);
            self::assertStringContainsString('role="progressbar"', $html);
            self::assertStringContainsString('aria-valuenow="' . $expected . '"', $html);
            self::assertStringContainsString('aria-valuemin="0"', $html);
            self::assertStringContainsString('aria-valuemax="100"', $html);
            self::assertStringContainsString('aria-label="Course progress"', $html);
            self::assertStringNotContainsString('&quot;', $html);
        }
    }
    public function testInvalidProgressRangeFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ComponentHarness::render('feedback.progress', ['value' => 1, 'label' => 'Progress', 'min' => 2, 'max' => 2]);
    }
}
