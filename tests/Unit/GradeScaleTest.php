<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\GradeScale;
use CattoLearning\Course\LegacyHtmlCourseImporter;
use PHPUnit\Framework\TestCase;

/** Guards the course grading scale: ranges, the [grading-scale] placeholder and reading the scale from legacy HTML. */
final class GradeScaleTest extends TestCase
{
    private const BANDS = [
        ['grade_code' => 'C', 'grade_label' => 'Third (C)', 'minimum_percentage' => 50, 'is_passing' => true],
        ['grade_code' => 'A', 'grade_label' => 'First Class (A)', 'minimum_percentage' => 75, 'is_passing' => true],
        ['grade_code' => 'Fail', 'grade_label' => 'Fail <b>', 'minimum_percentage' => 0, 'is_passing' => false],
    ];

    public function testEachBandRunsToJustBelowTheBandAbove(): void
    {
        $ranges = GradeScale::ranges(self::BANDS);
        self::assertSame(['75–100%', '50–74%', '0–49%'], array_column($ranges, 'range_label'));
        self::assertSame(['75', '50', '0'], array_column($ranges, 'minimum_label'));
        $decimal = GradeScale::ranges([['minimum_percentage' => 49.5, 'grade_label' => 'P'], ['minimum_percentage' => 0, 'grade_label' => 'F']]);
        self::assertSame(['49.5–100%', '0–49.49%'], array_column($decimal, 'range_label'));
    }

    public function testPlaceholderShowsTheCurrentEscapedScale(): void
    {
        $html = GradeScale::resolve('<h3>Grading scale</h3>[grading-scale]<p>After</p>', self::BANDS);
        self::assertStringContainsString('<ul class="cl-grading-scale scale-list"><li><span>First Class (A)</span><b>75–100%</b></li>', $html);
        self::assertStringContainsString('<span>Fail &lt;b&gt;</span><b>0–49%</b>', $html);
        self::assertStringNotContainsString('[grading-scale]', $html);
        self::assertSame('<p>No placeholder</p>', GradeScale::resolve('<p>No placeholder</p>', self::BANDS));
    }

    public function testImporterReadsTheShownScaleAndRemovesStandaloneOnlyParts(): void
    {
        $analysis = (new LegacyHtmlCourseImporter())->analyse(self::course(
            '<section class="info"><p>Mark each assessment. Progress is saved in this browser on this device.</p></section>'
            . '<section class="info scale"><h3>Grading scale</h3><ul class="scale-list"><li><span>Distinction</span><b>80-100%</b></li><li><span>Pass</span><b>55 - 79%</b></li><li><span>Fail</span><b>0–54%</b></li></ul>'
            . '<button class="reset-link" id="resetBtn" type="button">Reset all saved progress</button></section>',
            "function bandFor(pct) {\n  if (pct >= 80) return {label: 'Distinction', code: 'D', cls: 'x'};\n  if (pct >= 55) return {label: 'Pass', code: 'P', cls: 'y'};\n  return {label: 'Fail', code: 'F', cls: 'z'};\n}"
        ), 'scale.html');

        self::assertSame(['D', 'P', 'F'], array_column($analysis['grade_bands'], 'grade_code'));
        self::assertSame([80.0, 55.0, 0.0], array_column($analysis['grade_bands'], 'minimum_percentage'));
        self::assertSame([true, true, false], array_column($analysis['grade_bands'], 'is_passing'));
        self::assertSame('list', $analysis['grade_scale']['source']);
        self::assertTrue($analysis['grade_scale']['function_agrees']);
        $introduction = (string) $analysis['course']['introduction_html'];
        self::assertStringContainsString('[grading-scale]', $introduction);
        self::assertStringNotContainsString('scale-list', $introduction);
        self::assertStringNotContainsString('resetBtn', $introduction);
        self::assertStringNotContainsString('this browser', $introduction);
        self::assertStringContainsString('Mark each assessment.', $introduction);
        self::assertStringContainsString('<h3>Grading scale</h3>', $introduction);
    }

    public function testImporterFallsBackToTheGradingScriptThenTheStandardScale(): void
    {
        $scripted = (new LegacyHtmlCourseImporter())->analyse(self::course('<p>Intro</p>', "function gradeBand(p){if(p>=65)return['Merit','m'];return['Not yet','n'];}"), 'script.html');
        self::assertSame('function', $scripted['grade_scale']['source']);
        self::assertSame(['Merit', 'Not yet'], array_column($scripted['grade_bands'], 'grade_label'));
        self::assertSame([65.0, 0.0], array_column($scripted['grade_bands'], 'minimum_percentage'));

        $none = (new LegacyHtmlCourseImporter())->analyse(self::course('<p>Intro</p>', ''), 'none.html');
        self::assertSame('default', $none['grade_scale']['source']);
        self::assertSame(['A', 'B+', 'B', 'C', 'Fail'], array_column($none['grade_bands'], 'grade_code'));
        self::assertContains('No grading scale was found in the file, so the standard five grade bands were used. Check them on the Grades tab.', $none['warnings']);
    }

    private static function course(string $introduction, string $script): string
    {
        return '<!doctype html><html><head><title>Scale Course</title></head><body><header class="masthead"><h1>Scale Course</h1></header>'
            . $introduction
            . '<div class="modules"><section class="module" id="mod-m1" data-assessment-required="false"><div class="m-titles"><span class="m-name">One</span></div><div class="module-body"><p>Content.</p></div></section></div>'
            . '<script>const QUIZ={"final":[{"q":"Final?","o":["Yes","No"],"a":0}]};' . "\n" . $script . '</script></body></html>';
    }
}
