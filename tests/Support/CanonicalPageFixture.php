<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Support;

use CattoLearning\View\Twig\TwigFactory;
use CattoLearning\View\Twig\ThemeTemplates;

/** Renders bundled theme shells with isolated presentation data for structural contracts. */
final class CanonicalPageFixture
{
    public static function render(string $slug, bool $authenticated): string
    {
        $root = dirname(__DIR__, 2);
        $loader = TwigFactory::loader($root);
        $paths = [$root . '/themes/' . $slug];
        if ($slug === 'factory-reset-sidebar') $paths[] = $root . '/themes/factory-reset';
        (new ThemeTemplates($loader))->use($paths);
        $twig = TwigFactory::create($loader, $root, sys_get_temp_dir() . '/catto-canonical-' . getmypid(), true);
        $item = static fn(string $key, string $href, array $children = [], string $method = 'GET'): array => [
            'key' => $key, 'label' => ucfirst($key), 'href' => $href, 'icon_id' => 'nav-account',
            'active' => true, 'children' => $children, 'method' => $method, 'csrf' => 'test-csrf',
        ];
        $navigation = [$item('home', '/'), $item('catalogue', '/courses')];
        if ($authenticated) {
            $navigation[] = $item('account', '/account', [$item('profile', '/account/profile', [$item('emails', '/account/emails')])]);
            $navigation[] = $item('signout', '/logout', [], 'POST');
        } else {
            $navigation[] = $item('signin', '/login');
        }
        $layout = is_file($paths[0] . '/pages/content.html.twig') ? '@theme/pages/content.html.twig' : '@theme/base.html.twig';
        return $twig->createTemplate("{% extends layout %}{% block page_body %}{% include '@platform/partials/page-head.html.twig' %}<section class=\"cl-page-section\" aria-label=\"Learning guide\">{{ ui('feedback.empty', {heading: 'Learning', summary: 'Course content'}) }}</section>{% endblock %}")->render([
            'layout' => $layout, 'title' => 'Help', 'app_name' => 'Catto', 'body_class' => '',
            'page' => ['title' => 'Help', 'subtitle' => 'Learning', 'type' => 'content', 'body_class' => ''],
            'app' => ['name' => 'Catto', 'base_url' => '/'],
            'platform' => ['styles' => [], 'scripts' => [], 'icon_sprite' => '/img/nav-icons.svg'],
            'theme' => ['name' => $slug, 'slug' => $slug, 'version' => '2.0.0', 'asset_url' => '/theme', 'styles' => [], 'scripts' => [], 'external_styles' => [], 'external_scripts' => []],
            'user' => ['logged_in' => $authenticated, 'name' => 'Reader'], 'is_authenticated' => $authenticated,
            'identity_placement' => $slug === 'gilded-noir' ? 'navigation' : 'page',
            'identity_display_name' => 'Reader', 'identity_name' => 'Course Reader', 'identity_email' => 'reader@example.test', 'identity_role' => 'ADMIN',
            'identity_roles' => ['ADMIN'], 'identity_status' => 'Active',
            'course_content_mode' => false, 'theme_preview_active' => false,
            'navigation' => $navigation, 'footer_navigation' => [$item('help', '/help')],
            'breadcrumbs' => [['label' => 'Help', 'href' => null, 'current' => true]],
            'cart_summary' => ['count' => 0, 'items' => [], 'total_label' => 'R0.00'],
            'flash_messages' => [['type' => 'success', 'message' => 'Saved']],
            'ph_title' => 'Help', 'ph_kicker' => 'Learning', 'ph_lead' => 'Use the site', 'ph_action_href' => '/courses', 'ph_action_label' => 'Courses',
        ]);
    }
}
