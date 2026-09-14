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
        $html = RenderHarness::render('ui/empty-state', ['heading' => '<script>bad()</script>', 'summary' => 'A & B']);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        $grid = RenderHarness::render('ui/category-grid', ['navigation' => ['roots' => []]]);
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
            'cl-category-tile' => 'ui/category-tile',
            'cl-filter-pill' => 'ui/filter-pill',
            'cl-course-cards' => 'ui/course-grid',
            'cl-tag-chip' => 'ui/tag-chip',
            'cl-category-grid' => 'ui/category-grid',
            'cl-filter-rail' => 'ui/filter-rail',
            'cl-tag-browser' => 'ui/tag-browser',
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
            if (preg_match('/<article\b[^>]*class=["\'][^"\']*card/', $source) === 1 && str_contains($source, '/courses/')) {
                self::assertSame('resources/views/partials/course-card.html.twig', $path, $path . ' creates a second course-card implementation.');
            }
        }
        self::assertCount(count($owners), $found, 'The ownership scan must find every canonical component.');
    }

    public function testCataloguePagesComposeTheSameWorkspaceWithoutLocalResultsMarkup(): void
    {
        $templates = self::templates();
        foreach (['courses', 'course-tags'] as $page) {
            $path = 'resources/views/pages/' . $page . '.html.twig';
            $source = $templates[$path];
            self::assertStringContainsString("ui('catalogue-workspace'", $source, $path);
            self::assertStringContainsString('partials/dataset-search.html.twig', $source, $path);
            self::assertDoesNotMatchRegularExpression('/<(article|details|input)\b/', $source, $path . ' must compose canonical controls.');
            self::assertStringNotContainsString('partials/course-card.html.twig', $source, $path . ' must use the canonical course grid.');
            self::assertStringNotContainsString('partials/pagination.html.twig', $source, $path . ' pagination belongs to the workspace.');
        }
        $workspace = $templates['resources/views/ui/catalogue-workspace.html.twig'];
        foreach (['catalogue-workspace', 'catalogue-region', 'catalogue-results'] as $id) {
            self::assertSame(1, substr_count($workspace, 'id="' . $id . '"'));
        }
        self::assertSame(2, substr_count($workspace, 'partials/pagination.html.twig'));
        self::assertStringContainsString("ui('course-grid'", $workspace);
        self::assertSame(1, substr_count($templates['resources/views/ui/course-grid.html.twig'], 'partials/course-card.html.twig'));
    }

    public function testNavigationKeepsRealUrlsAndServerAuthoritativePersistentState(): void
    {
        $templates = self::templates();
        foreach (['category-tile', 'filter-pill'] as $component) {
            $source = $templates['resources/views/ui/' . $component . '.html.twig'];
            foreach (['href="{{ item.href }}"', 'hx-get="{{ item.href }}"', 'hx-target="#catalogue-region"', 'hx-select="#catalogue-results"', 'hx-swap="innerHTML"', 'hx-push-url="true"', 'hx-select-oob="#catalogue-categories,#catalogue-search"', 'aria-current='] as $contract) {
                self::assertStringContainsString($contract, $source, $component . ' must preserve ' . $contract);
            }
        }
        $tagBrowser = $templates['resources/views/ui/tag-browser.html.twig'];
        self::assertStringNotContainsString('<details', $tagBrowser);
        self::assertStringNotContainsString('|slice', $tagBrowser, 'The server must emit the complete tag vocabulary.');
        self::assertStringContainsString('data-tag-cloud-limit-value="80"', $tagBrowser);
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
        (new \CattoLearning\View\Ui\PlatformUi())->render($twig, [], 'empty-state', ['heading' => 'Example', 'class' => 'page-local-variant']);
    }
}
