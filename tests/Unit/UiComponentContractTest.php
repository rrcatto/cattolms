<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Tests\Support\RenderHarness;
use PHPUnit\Framework\TestCase;

/** Enforces canonical structural ownership across core views and all bundled theme templates. */
final class UiComponentContractTest extends TestCase
{
    public function testComponentTextIsEscapedByTheSameTwigEnvironmentAsRuntime(): void
    {
        $html = \CattoLearning\Tests\Support\ComponentHarness::render('feedback.empty', ['heading' => '<script>bad()</script>', 'summary' => 'A & B']);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        $grid = \CattoLearning\Tests\Support\ComponentHarness::render('catalogue.category-grid', ['navigation' => ['roots' => []]]);
        self::assertStringContainsString('cl-category-grid', $grid);
    }

    /** @return array<string,string> */
    private static function templates(): array
    {
        $root = dirname(__DIR__, 2);
        $templates = [];
        foreach (['resources/views', 'themes'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $path = substr($file->getPathname(), strlen($root) + 1);
                    $templates[$path] = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file->getPathname()));
                }
            }
        }
        return $templates;
    }

    public function testReusableMarkupHasExactlyOneOwnerIncludingInThemes(): void
    {
        $owners = [
            'cl-course-card' => 'partials/course-card',
            'cl-ui-surface' => 'ui/layout/surface',
            'cl-ui-section-grid' => 'ui/layout/section-grid',
            'cl-ui-section-head' => 'ui/layout/section-head',
            'cl-ui-toolbar' => 'ui/layout/toolbar',
            'cl-ui-breadcrumb' => 'ui/layout/breadcrumb',
            'cl-ui-row-actions' => 'ui/actions/row-actions',
            'cl-ui-row-menu' => 'ui/actions/row-actions',
            'cl-ui-action-group' => 'ui/actions/group',
            'cl-ui-form-grid' => 'ui/forms/form-grid',
            'cl-ui-field' => 'ui/forms/field',
            'cl-ui-form-actions' => 'ui/forms/form-actions',
            'cl-ui-choice' => 'ui/forms/choice-field',
            'cl-ui-compact-action' => 'ui/forms/compact-action',
            'cl-ui-table' => 'ui/data/data-table',
            'cl-ui-stat-card' => 'ui/data/stat-card',
            'cl-ui-stat-grid' => 'ui/data/stat-grid',
            'cl-ui-list' => 'ui/data/item-list',
            'cl-ui-list-item' => 'ui/data/list-item',
            'cl-ui-key-value-list' => 'ui/data/key-value-list',
            'cl-ui-badge' => 'ui/feedback/badge',
            'cl-ui-notice' => 'ui/feedback/notice',
            'cl-ui-empty-state' => 'ui/feedback/empty-state',
            'cl-ui-progress' => 'ui/feedback/progress',
            'cl-ui-modal' => 'ui/overlay/modal',
            'cl-ui-accordion-section' => 'ui/overlay/accordion-section',
            'cl-ui-icon' => 'ui/helpers/icon',
            'cl-category-tile' => 'ui/catalogue/category-tile',
            'cl-filter-pill' => 'ui/catalogue/filter-pill',
            'cl-course-cards' => 'ui/catalogue/course-grid',
            'cl-tag-chip' => 'ui/catalogue/tag-chip',
            'cl-category-grid' => 'ui/catalogue/category-grid',
            'cl-filter-rail' => 'ui/catalogue/filter-rail',
            'cl-tag-browser' => 'ui/catalogue/tag-browser',
        ];
        $found = [];
        foreach (self::templates() as $path => $source) {
            preg_match_all('/class\s*=\s*["\']([^"\']*)["\']/', $source, $attributes);
            foreach ($owners as $class => $owner) {
                foreach ($attributes[1] as $attribute) {
                    if (preg_match('/(?<![a-z0-9_-])' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $attribute) !== 1) continue;
                    $expected = 'resources/views/' . $owner . '.html.twig';
                    self::assertSame($expected, $path, $path . ' duplicates ' . $class . '; compose ' . $expected . ' through PlatformUi instead.');
                    $found[$class] = true;
                }
            }
            if (preg_match('/<article\b[^>]*class=["\'][^"\']*cl-ui-surface/', $source) === 1 && str_contains($source, '/courses/')) {
                self::assertSame('resources/views/partials/course-card.html.twig', $path, $path . ' creates a second course-card implementation.');
            }
        }
        self::assertCount(count($owners), $found, 'The ownership scan must find every canonical component.');
    }

    public function testLogicalNamesAreNamespacedAndHaveNoTemplateAliases(): void
    {
        $templates = [];
        foreach (\CattoLearning\View\Ui\PlatformUi::COMPONENTS as $name => $definition) {
            self::assertTrue($name === 'icon' || str_contains($name, '.'), $name . ' is not namespaced.');
            self::assertStringContainsString('/', $definition['template']);
            $templates[] = $definition['template'];
        }
        self::assertSame($templates, array_values(array_unique($templates)), 'Each logical component has one canonical template; aliases are forbidden.');
    }

    public function testStructuralSignaturesCannotBeReimplementedUnderAnotherClassName(): void
    {
        foreach (self::templates() as $path => $source) {
            if (preg_match('/<table\b/', $source) === 1) self::assertSame('resources/views/ui/data/data-table.html.twig', $path);
            if (str_contains($source, 'role="dialog"')) self::assertSame('resources/views/ui/overlay/modal.html.twig', $path);
            if (str_contains($source, 'role="progressbar"')) self::assertSame('resources/views/ui/feedback/progress.html.twig', $path);
            if (str_contains($source, 'partials/pagination.html.twig')) {
                self::assertContains($path, ['resources/views/ui/data/dataset-layout.html.twig', 'resources/views/ui/catalogue/catalogue-workspace.html.twig']);
            }
        }
    }

    public function testCataloguePagesComposeTheSameWorkspaceWithoutLocalResultsMarkup(): void
    {
        $templates = self::templates();
        foreach (['courses', 'course-tags'] as $page) {
            $path = 'resources/views/pages/' . $page . '.html.twig';
            $source = $templates[$path];
            self::assertStringContainsString("ui('catalogue.workspace'", $source, $path);
            self::assertStringContainsString('partials/dataset-search.html.twig', $source, $path);
            self::assertDoesNotMatchRegularExpression('/<(article|details|input)\b/', $source, $path . ' must compose canonical controls.');
            self::assertStringNotContainsString('partials/course-card.html.twig', $source, $path . ' must use the canonical course grid.');
            self::assertStringNotContainsString('partials/pagination.html.twig', $source, $path . ' pagination belongs to the workspace.');
        }
        $workspace = $templates['resources/views/ui/catalogue/catalogue-workspace.html.twig'];
        foreach (['catalogue-workspace', 'catalogue-region', 'catalogue-results'] as $id) {
            self::assertSame(1, substr_count($workspace, 'id="' . $id . '"'));
        }
        self::assertSame(2, substr_count($workspace, 'partials/pagination.html.twig'));
        self::assertStringContainsString("ui('catalogue.course-grid'", $workspace);
        self::assertSame(1, substr_count($templates['resources/views/ui/catalogue/course-grid.html.twig'], 'partials/course-card.html.twig'));
    }

    public function testNavigationKeepsRealUrlsAndServerAuthoritativePersistentState(): void
    {
        $templates = self::templates();
        foreach (['category-tile', 'filter-pill'] as $component) {
            $source = $templates['resources/views/ui/catalogue/' . $component . '.html.twig'];
            foreach (['href="{{ item.href }}"', 'hx-get="{{ item.href }}"', 'hx-target="#catalogue-region"', 'hx-select="#catalogue-results"', 'hx-swap="innerHTML"', 'hx-push-url="true"', 'hx-select-oob="#catalogue-categories,#catalogue-search"', 'aria-current='] as $contract) {
                self::assertStringContainsString($contract, $source, $component . ' must preserve ' . $contract);
            }
        }
        $tagBrowser = $templates['resources/views/ui/catalogue/tag-browser.html.twig'];
        self::assertStringNotContainsString('<details', $tagBrowser);
        self::assertStringNotContainsString('|slice', $tagBrowser, 'The server must emit the complete tag vocabulary.');
        self::assertStringContainsString('data-tag-cloud-limit-value="80"', $tagBrowser);
    }

    public function testCoursePresentationNavigationComposesCanonicalActions(): void
    {
        $templates = self::templates();
        foreach (['learn-course', 'learn-item', 'course-public-preview', 'course-public-preview-item'] as $page) {
            $source = $templates['resources/views/pages/' . $page . '.html.twig'];
            self::assertStringContainsString("ui('action.", $source, $page . ' must compose canonical actions.');
        }
        foreach (['learn-item', 'course-public-preview-item'] as $page) {
            $source = $templates['resources/views/pages/' . $page . '.html.twig'];
            self::assertStringNotContainsString('<a href=', $source, $page . ' must not recreate course paging links.');
        }
        foreach (['assessment-overview', 'assessment-public-preview'] as $page) {
            $source = $templates['resources/views/pages/' . $page . '.html.twig'];
            self::assertStringContainsString('cl-course-item-paging', $source, $page . ' must provide course navigation.');
            self::assertStringContainsString("ui('action.link'", $source, $page . ' must compose canonical navigation actions.');
        }
    }

    public function testEveryCourseItemPageComposesTheSameCoreArticleLayout(): void
    {
        $templates = self::templates();
        foreach (['learn-item', 'course-public-preview-item', 'assessment-overview', 'assessment-question', 'assessment-session-result', 'assessment-public-preview'] as $page) {
            $source = $templates['resources/views/pages/' . $page . '.html.twig'];
            self::assertStringContainsString("extends '@platform/layout/course-item.html.twig'", $source, $page . ' must use the standard Course Item page construction.');
            self::assertStringContainsString('block course_item_body', $source, $page . ' must render inside the standard Course Item article.');
        }
    }

    public function testCourseReaderExcludesTheThemePaletteSwitcher(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertStringContainsString('data-cl-course-reader="1"', (string) file_get_contents($root . '/resources/views/layout/course-presentation.html.twig'));
        self::assertStringContainsString("document.body.dataset.clCourseReader==='1'", (string) file_get_contents($root . '/public_html/js/platform-overrides.js'));
        self::assertStringContainsString("!in_array(\$family, ['course-player', 'assessment'], true)", (string) file_get_contents($root . '/src/View/ThemeRenderer.php'));
        self::assertStringContainsString('body[data-cl-course-reader="1"] .cl-palette-switcher', (string) file_get_contents($root . '/public_html/css/catto-platform.css'));
    }

    public function testCourseReaderAssessmentLinksBypassTheGenericItemPage(): void
    {
        $source = self::templates()['resources/views/layout/course-presentation.html.twig'];
        self::assertStringContainsString("node.item_type in ['assessment', 'diagnostic']", $source);
        self::assertStringContainsString("'/assessment/' ~ node.item_key", $source);
        self::assertStringContainsString("'/content'", $source);
        $root = dirname(__DIR__, 2);
        foreach (['src/Http/Controller/LearningController.php', 'src/Http/Controller/CoursePresentationController.php'] as $path) {
            $controller = (string) file_get_contents($root . '/' . $path);
            self::assertStringContainsString("throw \$this->notFound('Assessments open from their direct assessment route.')", $controller);
        }
        $renderer = (string) file_get_contents($root . '/src/Course/CourseItemRenderer.php');
        self::assertStringNotContainsString('cl-course-item-assessment"><summary>', $renderer);
    }

    public function testCourseReaderSidebarKeepsItsIntroductionAndAssessmentLabelsConcise(): void
    {
        $source = self::templates()['resources/views/layout/course-presentation.html.twig'];
        self::assertStringContainsString('cl-course-outline-introduction', $source);
        self::assertStringContainsString("'Module ' ~ node.assessment_module_number ~ ' '", $source);
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/css/catto-platform.css');
        self::assertStringContainsString('.cl-course-outline-introduction.is-current', $css);
        self::assertStringContainsString('.cl-course-outline-title{font-size:.95rem;font-weight:800', $css);
    }

    public function testDraftPublicPreviewExitsToCourseContentRatherThanThePublicCatalogue(): void
    {
        $source = self::templates()['resources/views/layout/course-presentation.html.twig'];
        self::assertStringContainsString("admin_public_preview = public_preview and course.public_preview_query|default('') == '?preview=1'", $source);
        self::assertStringContainsString("'/admin/courses/' ~ course.id ~ '/content'", $source);
        self::assertStringContainsString("'Exit public preview'", $source);
    }

    public function testImportedCourseIntroductionKeepsMastheadAndCardsOnOneWidth(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/css/catto-platform.css');
        self::assertStringContainsString('.cl-course-presentation-intro{padding:clamp(1.4rem,5vw,3.2rem)!important}', $css);
        self::assertStringContainsString('.cl-course-presentation-intro .masthead{margin:0 0 1.5rem!important}', $css);
        self::assertStringContainsString('.cl-course-presentation-intro .course-introduction-copy{max-width:none;margin:0}', $css);
    }

    public function testObsoletePublicCategoryImplementationsAreAbsent(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['category-branch', 'category-courses', 'tag-cloud'] as $obsolete) {
            self::assertFileDoesNotExist($root . '/resources/views/partials/' . $obsolete . '.html.twig');
        }
        foreach (self::templates() as $path => $source) {
            self::assertStringNotContainsString('?open=', $source, $path . ' restores obsolete category state.');
            self::assertStringNotContainsString('category-branch.html.twig', $source, $path . ' restores recursive category rendering.');
        }
        $controller = (string) file_get_contents($root . '/src/Http/Controller/CourseController.php');
        self::assertStringNotContainsString('function tagIndex(', $controller, 'CourseTagController alone owns tag discovery.');
        self::assertStringNotContainsString('categoryCoursesFragment', $controller);
    }

    public function testComponentApiRejectsArbitraryStructuralProperties(): void
    {
        $loader = \CattoLearning\View\Twig\TwigFactory::loader(dirname(__DIR__, 2));
        $twig = \CattoLearning\View\Twig\TwigFactory::create($loader, dirname(__DIR__, 2), sys_get_temp_dir() . '/catto-ui-contract-' . getmypid(), true);
        $this->expectException(\InvalidArgumentException::class);
        (new \CattoLearning\View\Ui\PlatformUi())->render($twig, [], 'feedback.empty', ['heading' => 'Example', 'class' => 'page-local-variant']);
    }

    public function testSupersededStructuresAndFlatComponentAliasesCannotReturn(): void
    {
        $obsolete = ['card', 'card-body', 'card-header', 'section-head', 'notice', 'badge', 'modal-backdrop', 'modal-card', 'modal-foot', 'stat-card', 'cl-form-actions', 'row-actions', 'table-wrap', 'empty', 'cl-empty-state', 'form-grid', 'field', 'list-item', 'list-group', 'list-group-item', 'cl-stat', 'cl-summary-card', 'cl-step-badge', 'acl-group', 'contact-card', 'admin-wide-card', 'cl-start-card', 'btn', 'alert', 'cl-help-group', 'cl-help-item', 'gn-btn', 'form-check'];
        foreach (self::templates() as $path => $source) {
            preg_match_all('/class="([^"]*)"/', $source, $matches);
            foreach ($matches[1] as $classes) {
                foreach ($obsolete as $class) {
                    self::assertDoesNotMatchRegularExpression('/(?<![a-z0-9_-])' . preg_quote($class, '/') . '(?![a-z0-9_-])/', $classes, $path . ' restores ' . $class);
                }
            }
            self::assertDoesNotMatchRegularExpression("/ui\\('(surface|section-head|empty-state|course-grid|catalogue-workspace)'/", $source, $path);
        }
        self::assertSame([], glob(dirname(__DIR__, 2) . '/resources/views/ui/*.twig'));
    }
}
