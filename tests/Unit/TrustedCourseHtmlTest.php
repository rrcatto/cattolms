<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseHtml;
use CattoLearning\Course\LegacyHtmlCourseImporter;
use CattoLearning\Course\StructuredCourseImporter;
use PHPUnit\Framework\TestCase;
use CattoLearning\Tests\Support\TrustedCourseContent;

final class TrustedCourseHtmlTest extends TestCase
{
    public function testTrustedFragmentsArePreservedWithoutAnAllowlist(): void
    {
        $fragment = TrustedCourseContent::SVG . '<style>.diagram{display:grid;filter:blur(0)}</style><button onclick="this.dataset.clicked=1">Try</button><script>window.lessonReady=true;</script><img src="data:image/svg+xml,%3Csvg%3E%3C/svg%3E"><iframe src="/lesson-demo"></iframe>';
        self::assertSame($fragment, (new CourseHtml())->preserve($fragment));
        $data = (new StructuredCourseImporter())->analyse(['course' => ['title' => 'Trusted', 'description_html' => $fragment], 'modules' => [['title' => 'Lesson', 'content_html' => $fragment, 'summary_html' => $fragment, 'assessment_required' => false]], 'final_assessment' => ['questions' => [['question_html' => $fragment, 'explanation_html' => $fragment, 'options' => [['option_html' => $fragment, 'is_correct' => true], ['option_html' => 'Other']]]]]], 'trusted.json');
        self::assertSame($fragment, $data['course']['description_html']);
        self::assertSame($fragment, $data['modules'][0]['content_html']);
        self::assertSame($fragment, $data['modules'][0]['summary_html']);
        self::assertSame($fragment, $data['final_assessment']['questions'][0]['question_html']);
        self::assertSame($fragment, $data['final_assessment']['questions'][0]['options'][0]['option_html']);
        self::assertSame($fragment, $data['final_assessment']['questions'][0]['explanation_html']);
    }

    public function testHtmlImportPreservesSvgCaseNamespacesAndEmbeddedCode(): void
    {
        $html = '<!doctype html><html><head><title>Trusted lesson</title><link rel="stylesheet" href="https://courses.example.test/author.css"><style>@import url("https://courses.example.test/diagrams.css"); .diagram{display:grid}</style></head><body><section class="module" id="mod-m1" data-assessment-required="false"><span class="m-name">Diagram</span><div class="module-body"><div class="outcomes"><span>Learning outcomes</span>' . TrustedCourseContent::SVG . '</div>' . TrustedCourseContent::SVG . '<script>window.lessonReady=true;</script><style>.local{fill:red}</style><button onclick="this.dataset.clicked=1">Try</button></div></section><script>const QUIZ={};</script></body></html>';
        $data = (new LegacyHtmlCourseImporter())->analyse($html, 'trusted.html');
        foreach (['content_html', 'learning_outcomes_html'] as $field) {
            $fragment = $data['modules'][0][$field];
            foreach (['viewBox="0 0 120 60"', '<linearGradient', '<clipPath', '<foreignObject', 'fill="url(#lessonGradient)"', 'http://www.w3.org/2000/svg'] as $required) self::assertStringContainsString($required, $fragment);
        }
        self::assertStringContainsString('Learning outcomes', $data['modules'][0]['learning_outcomes_html']);
        self::assertStringContainsString('<script>window.lessonReady=true;</script>', $data['modules'][0]['content_html']);
        self::assertStringContainsString('onclick="this.dataset.clicked=1"', $data['modules'][0]['content_html']);
        self::assertStringContainsString('<style>.local{fill:red}</style>', $data['modules'][0]['content_html']);
        self::assertStringContainsString('@import url("https://courses.example.test/author.css");', $data['course']['presentation_css']);
        self::assertStringContainsString('@import url("https://courses.example.test/diagrams.css");', $data['course']['presentation_css']);
        self::assertStringContainsString('.cl-course-presentation .diagram{display:grid}', $data['course']['presentation_css']);
    }
}
