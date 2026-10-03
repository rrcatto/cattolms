<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\CourseItemRenderer;
use CattoLearning\Course\CourseItemRepository;
use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\ComponentHarness;
use PHPUnit\Framework\TestCase;

/**
 * The course reader's presentation contracts: one owner for each Course Item description, Download
 * as the shared primary action, white text on the reader's blue primary action whatever authored
 * course CSS says about links, and a distinct Section entry in the Course Modules outline.
 */
final class CourseReaderContractTest extends TestCase
{
    private const DESCRIPTION = '<p>Only once, please.</p>';

    public function testTheRendererPrintsEveryTypesDescriptionExactlyOnce(): void
    {
        $renderer = $this->renderer();
        $base = ['item_key' => 'reader-item', 'title' => 'Reader item', 'description_html' => self::DESCRIPTION, 'type_config' => ['youtube_id' => 'abc', 'caption' => 'Caption'], 'resource_id' => null];
        foreach (['html_lesson', 'markdown', 'youtube', 'audio', 'assessment', 'diagnostic', 'downloadable_file'] as $type) {
            $html = $renderer->render(['item_type' => $type, 'content_source' => '<p>Body</p>'] + $base, 'reader', 7);
            self::assertSame(1, substr_count($html, self::DESCRIPTION), $type . ' prints its description once.');
        }
        $download = $renderer->render(['item_type' => 'downloadable_file'] + $base, 'reader', 7);
        self::assertMatchesRegularExpression('~^<section class="cl-course-item-download">' . preg_quote(self::DESCRIPTION, '~') . '<p class="cl-course-item-download-meta">~', $download, 'A Downloadable File keeps its description inside the card.');
        $lesson = $renderer->render(['item_type' => 'html_lesson', 'content_source' => '<p>Body</p>'] + $base, 'reader', 7);
        self::assertSame('<div class="cl-course-item-description">' . self::DESCRIPTION . '</div><p>Body</p>', $lesson, 'A lesson prints its description above its content.');
        $override = $renderer->render(['item_type' => 'downloadable_file', 'display_description_override' => '<p>This course only.</p>'] + $base, 'reader', 7);
        self::assertStringContainsString('<p>This course only.</p>', $override, "A placement's description override replaces the item's own.");
        self::assertStringNotContainsString(self::DESCRIPTION, $override);
    }

    public function testItemPagesLeaveDescriptionsToTheRenderer(): void
    {
        foreach (['learn-item', 'course-public-preview-item'] as $page) {
            $source = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/pages/' . $page . '.html.twig');
            self::assertStringNotContainsString('description_html', $source, $page . ' must not print a description beside the rendered item.');
            self::assertStringNotContainsString('display_description_override', $source, $page . ' must not print a description beside the rendered item.');
            self::assertStringContainsString('item.rendered_html|raw', $source);
        }
    }

    public function testDownloadIsTheSharedPrimaryAction(): void
    {
        $link = ComponentHarness::render('action.link', ['label' => 'Download', 'href' => '/download', 'variant' => 'primary', 'size' => 'normal']);
        self::assertSame(1, preg_match('/<a [^>]*class="([^"]+)"/', $link, $component));
        self::assertSame($component[1] . ' cl-course-item-download-link', CourseItemRenderer::DOWNLOAD_ACTION_CLASSES, 'Download carries exactly the component classes plus its own hook.');
        $css = $this->css();
        self::assertSame(1, preg_match('/\.cl-course-item-download-link\{([^}]*)\}/', $css, $own));
        foreach (['background', 'color', 'padding', 'border-radius', 'font-weight'] as $property) {
            self::assertDoesNotMatchRegularExpression('/(^|;)' . $property . ':/', $own[1], 'Download does not restate the shared action\'s ' . $property . '.');
        }
        self::assertStringNotContainsString('.cl-course-item-download-link:hover', $css);
        self::assertStringNotContainsString('.cl-course-item-paging a', $css, 'Previous and Next use the shared action too, not a re-created button.');
    }

    public function testTheReadersBluePrimaryActionHasWhiteTextAboveAuthoredLinkRules(): void
    {
        $css = $this->css();
        $base = '.cl-course-presentation-shell .cl-ui-action.cl-ui-action--primary,.cl-course-presentation-shell .cl-ui-action.cl-ui-action--primary:visited{';
        self::assertStringContainsString($base, $css);
        self::assertSame(1, preg_match('/' . preg_quote($base, '/') . '([^}]*)\}/', $css, $rule));
        self::assertStringContainsString('background:#145da0', $rule[1]);
        self::assertStringContainsString('color:#fff', $rule[1]);
        self::assertStringContainsString('font-weight:700', $rule[1], 'One shape for every reader primary action.');
        self::assertSame(1, preg_match('/\.cl-course-presentation-shell \.cl-ui-action\.cl-ui-action--primary:is\(:hover,:focus-visible,:active\)\{([^}]*)\}/', $css, $states));
        self::assertStringContainsString('color:#fff', $states[1], 'Hover, focus and active keep white text.');
        // The course's authored presentation CSS loads after this file; a link rule scoped to the
        // authored region, such as `.cl-course-presentation a{color:inherit}`, has specificity
        // (0,1,1). The reader's primary action rule must out-rank it.
        self::assertGreaterThan(self::specificity('.cl-course-presentation a'), self::specificity('.cl-course-presentation-shell .cl-ui-action.cl-ui-action--primary'));
        self::assertGreaterThan(self::specificity('.cl-course-presentation a:hover'), self::specificity('.cl-course-presentation-shell .cl-ui-action.cl-ui-action--primary:hover'));
        // Themes never style the reader: it loads only the platform stylesheets and the course's own.
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layout/course-presentation.html.twig');
        self::assertStringContainsString('{% for resourceUrl in platform.styles %}', $layout);
        self::assertStringNotContainsString('theme.styles', $layout);
        self::assertStringNotContainsString('theme_styles', $layout);
    }

    public function testSectionsHaveTheirOwnOutlineEntry(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/layout/course-presentation.html.twig');
        self::assertStringContainsString("{% if node.node_type == 'section' %} cl-course-outline-section{% endif %}", $layout);
        self::assertStringContainsString("{% set node_label = node.node_type == 'section' ? 'Section: ' ~ node_title : (", $layout);
        self::assertStringContainsString('cl-course-depth-{{ node.depth }}', $layout, 'Depth classes remain.');
        self::assertSame(1, preg_match('/\.cl-course-outline-item\.cl-course-outline-section>:is\(a,strong,span\)\{([^}]*)\}/', $this->css(), $rule), 'Linked, plain and locked section entries share one rule.');
        self::assertStringContainsString('font-weight:700', $rule[1]);
        self::assertStringContainsString('font-size:calc(1em + 2pt)', $rule[1]);
    }

    private function renderer(): CourseItemRenderer
    {
        $db = $this->createStub(Database::class);
        $db->method('fetchAssociative')->willReturn(false);
        $records = new CourseItemRepository($db);
        return new CourseItemRenderer($records, new ResourceLibraryService($records, sys_get_temp_dir(), static fn(string $path): bool => false));
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/css/catto-platform.css');
    }

    /** Specificity of a simple selector as one comparable number: ids, classes and pseudo-classes, elements. */
    private static function specificity(string $selector): int
    {
        $classes = preg_match_all('/\.[\w-]+|:(?!:)[\w-]+/', $selector);
        $elements = preg_match_all('/(?:^|[\s>+~])[a-z][\w-]*/i', $selector);
        return $classes * 100 + $elements;
    }
}
