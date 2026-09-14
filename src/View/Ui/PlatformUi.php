<?php

declare(strict_types=1);

namespace CattoLearning\View\Ui;

use InvalidArgumentException;
use Twig\Environment;
use Twig\Markup;

/** Presentation-only component catalogue. Twig owns markup and escaping; callers supply data. */
final class PlatformUi
{
    /** Explicit definitions bound both template ownership and the properties callers may supply. */
    private const COMPONENTS = [
        'surface' => [],
        'section-head' => ['heading', 'summary'],
        'empty-state' => ['heading', 'summary'],
        'category-grid' => ['navigation'],
        'category-tile' => ['item'],
        'filter-rail' => ['label', 'items'],
        'filter-pill' => ['item'],
        'course-grid' => ['courses'],
        'catalogue-workspace' => ['workspace', 'navigation', 'courses', 'catalogue_pagination'],
        'tag-browser' => ['tags', 'selected_slug'],
        'tag-chip' => ['tag', 'selected', 'compact'],
    ];

    /**
     * Only canonical templates may be rendered. Behaviour context preserves the existing card's
     * favourite/CSRF contract; no controller-provided HTML or structural class parameters exist.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $properties
     */
    public function render(Environment $twig, array $context, string $component, array $properties = []): Markup
    {
        $allowed = self::COMPONENTS[$component] ?? null;
        if ($allowed === null || array_diff(array_keys($properties), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown platform UI component or property: ' . $component);
        }
        $behaviour = array_intersect_key($context, array_flip(['csrf', 'can_favourite', 'favourite_return']));

        return new Markup($twig->render('@platform/ui/' . $component . '.html.twig', $properties + $behaviour), 'UTF-8');
    }
}
