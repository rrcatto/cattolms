<?php

declare(strict_types=1);

namespace CattoLearning\View;

use CattoPHP\Theme\PackageValidator;

/**
 * cattolms's terms for CattoPHP's theme system (CattoPHP Phase 3, step 9): the bundled theme a site starts on, its
 * package format's name, which every cattolms theme package says, and the page families a theme may wrap
 * (pages/<family>.html.twig), which ThemeRenderer::pageFamily() gives each page.
 */
final class ProjectTheme
{
    /** The theme the code ships as the default: discovered from the code root, never copied, never uninstalled. */
    public const DEFAULT_SLUG = 'saharan-sunset';

    public const PACKAGE_FORMAT = 'catto-learning-theme';

    public const PAGE_FAMILIES = [
        'home', 'catalogue', 'course-detail', 'auth', 'account', 'library', 'course-player',
        'assessment', 'certificate', 'commerce', 'company', 'admin', 'error', 'content',
    ];

    private function __construct()
    {
    }

    /** CattoPHP's package check with cattolms's format and families, as the site uses it. */
    public static function validator(): PackageValidator
    {
        return new PackageValidator(self::PACKAGE_FORMAT, self::PAGE_FAMILIES);
    }
}
