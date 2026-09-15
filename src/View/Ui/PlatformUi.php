<?php

declare(strict_types=1);

namespace CattoLearning\View\Ui;

use InvalidArgumentException;
use Twig\Environment;
use Twig\Markup;

/** Canonical presentation registry: bounded semantic properties and Twig-owned structure/slots. */
final class PlatformUi
{
    /** Sole logical-name to template/property ownership map. No aliases or dynamic paths. */
    public const COMPONENTS = [
        'layout.surface' => ['template' => 'layout/surface', 'defaults' => ['variant' => 'standard', 'section_spacing' => false, 'id' => '', 'sticky' => false, 'tone' => 'neutral']],
        'layout.section-grid' => ['template' => 'layout/section-grid', 'defaults' => ['columns' => 'auto', 'section_spacing' => true]],
        'layout.section-head' => ['template' => 'layout/section-head', 'defaults' => ['eyebrow' => '', 'heading' => null, 'summary' => '', 'heading_level' => 2]],
        'layout.toolbar' => ['template' => 'layout/toolbar', 'defaults' => []],
        'layout.breadcrumb' => ['template' => 'layout/breadcrumb', 'defaults' => ['items' => null, 'aria_label' => 'Breadcrumb']],
        'action.link' => ['template' => 'actions/link', 'defaults' => ['lookup_clear' => '', 'new_window' => false, 'rel' => '', 'aria_label' => '', 'navigation_key' => '', 'get' => '', 'target' => '', 'select' => '', 'push_url' => false, 'indicator' => '', 'label' => null, 'variant' => 'secondary', 'size' => 'normal', 'icon' => '', 'title' => '', 'id' => '', 'confirm' => '', 'modal' => '', 'full_width' => false, 'href' => null]],
        'action.button' => ['template' => 'actions/button', 'defaults' => ['lookup_clear' => '', 'aria_label' => '', 'get' => '', 'target' => '', 'select' => '', 'push_url' => false, 'indicator' => '', 'label' => null, 'variant' => 'secondary', 'size' => 'normal', 'icon' => '', 'title' => '', 'id' => '', 'confirm' => '', 'modal' => '', 'close_modal' => false, 'form' => '', 'name' => '', 'value' => '', 'stimulus_action' => '', 'disabled' => false, 'hidden' => false, 'expanded' => null, 'controls' => '', 'full_width' => false, 'type' => 'submit']],
        'action.group' => ['template' => 'actions/group', 'defaults' => []],
        'action.row' => ['template' => 'actions/row-actions', 'defaults' => ['menu' => false]],
        'form.grid' => ['template' => 'forms/form-grid', 'defaults' => ['columns' => 'auto']],
        'form.field' => ['template' => 'forms/field', 'defaults' => ['label' => null, 'for' => '', 'required' => false, 'full_width' => false, 'help' => '', 'error' => '']],
        'form.actions' => ['template' => 'forms/form-actions', 'defaults' => []],
        'form.choice' => ['template' => 'forms/choice-field', 'defaults' => ['label' => null, 'help' => '', 'full_width' => false]],
        'form.compact-action' => ['template' => 'forms/compact-action', 'defaults' => []],
        'data.table' => ['template' => 'data/data-table', 'defaults' => ['section_spacing' => false, 'density' => 'normal', 'caption' => '']],
        'data.dataset' => ['template' => 'data/dataset-layout', 'defaults' => ['name' => null, 'heading' => '', 'summary' => '', 'search' => [], 'pagination' => null]],
        'data.stat-grid' => ['template' => 'data/stat-grid', 'defaults' => ['columns' => 'auto'], 'props' => ['columns' => ['auto', 1, 2, 3, 4]]],
        'data.stat-card' => ['template' => 'data/stat-card', 'defaults' => ['value' => null, 'label' => null, 'note' => '']],
        'data.list' => ['template' => 'data/item-list', 'defaults' => []],
        'data.list-item' => ['template' => 'data/list-item', 'defaults' => []],
        'data.key-value-list' => ['template' => 'data/key-value-list', 'defaults' => ['items' => null]],
        'feedback.badge' => ['template' => 'feedback/badge', 'defaults' => ['label' => null, 'tone' => 'neutral', 'size' => 'normal']],
        'feedback.notice' => ['template' => 'feedback/notice', 'defaults' => ['tone' => 'info', 'heading' => '', 'section_spacing' => false, 'flash' => false]],
        'feedback.empty' => ['template' => 'feedback/empty-state', 'defaults' => ['heading' => '', 'summary' => '']],
        'feedback.progress' => ['template' => 'feedback/progress', 'defaults' => ['value' => null, 'min' => 0, 'max' => 100, 'label' => null, 'show_value' => false, 'size' => 'normal']],
        'overlay.modal' => ['template' => 'overlay/modal', 'defaults' => ['id' => null, 'title' => null, 'size' => 'normal', 'action' => '', 'method' => 'post']],
        'overlay.accordion-section' => ['template' => 'overlay/accordion-section', 'defaults' => ['id' => '', 'heading' => null, 'summary' => '', 'open' => false, 'workspace' => '', 'section_key' => '', 'load_url' => '']],
        'icon' => ['template' => 'helpers/icon', 'defaults' => ['name' => null, 'label' => '', 'decorative' => true, 'size' => 'normal']],
        'catalogue.category-grid' => ['template' => 'catalogue/category-grid', 'defaults' => ['navigation' => []]],
        'catalogue.category-tile' => ['template' => 'catalogue/category-tile', 'defaults' => ['item' => []]],
        'catalogue.filter-rail' => ['template' => 'catalogue/filter-rail', 'defaults' => ['label' => [], 'items' => []]],
        'catalogue.filter-pill' => ['template' => 'catalogue/filter-pill', 'defaults' => ['item' => []]],
        'catalogue.course-grid' => ['template' => 'catalogue/course-grid', 'defaults' => ['courses' => []]],
        'catalogue.workspace' => ['template' => 'catalogue/catalogue-workspace', 'defaults' => ['workspace' => [], 'navigation' => [], 'courses' => [], 'catalogue_pagination' => []]],
        'catalogue.tag-browser' => ['template' => 'catalogue/tag-browser', 'defaults' => ['tags' => [], 'selected_slug' => '']],
        'catalogue.tag-chip' => ['template' => 'catalogue/tag-chip', 'defaults' => ['tag' => [], 'selected' => false, 'compact' => false]],
    ];

    /**
     * Only control-side relationships owned by form.field; never an arbitrary attribute bag.
     * @param array<string,mixed> $properties
     */
    public function fieldAttributes(array $properties): Markup
    {
        $field = $this->properties('form.field', $properties);
        if (!is_string($field['for']) || preg_match('/\s/u', $field['for'])) {
            throw new InvalidArgumentException('form.field.for must be a single control ID.');
        }
        $ids = [];
        if ($field['for'] !== '') {
            if ($field['help']) $ids[] = $field['for'] . '-help';
            if ($field['error']) $ids[] = $field['for'] . '-error';
        }
        $attributes = $ids === [] ? '' : 'aria-describedby="' . htmlspecialchars(implode(' ', $ids), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        if ($field['error']) $attributes .= ($attributes === '' ? '' : ' ') . 'aria-invalid="true"';
        return new Markup($attributes, 'UTF-8');
    }

    /** Resolve only an explicitly registered component; safe for Twig embed slots. */
    public function template(string $component): string
    {
        $definition = self::COMPONENTS[$component] ?? throw new InvalidArgumentException('Unknown UI component: ' . $component);
        return '@platform/ui/' . $definition['template'] . '.html.twig';
    }

    /** Validate the same semantic contract for ui() and Twig embed slots.
     * @param array<string,mixed> $properties
     * @return array<string,mixed>
     */
    public function properties(string $component, array $properties = []): array
    {
        $this->template($component);
        $defaults = self::COMPONENTS[$component]['defaults'];
        if (array_diff_key($properties, $defaults) !== []) {
            throw new InvalidArgumentException('Unknown UI property for ' . $component . ': ' . implode(', ', array_keys(array_diff_key($properties, $defaults))));
        }
        array_walk_recursive($properties, static function (mixed $value): void {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('UI properties contain data, not objects or rendered markup.');
            }
        });
        $values = $properties + $defaults;
        foreach ($defaults as $key => $default) {
            if ($default === null && $values[$key] === null && !in_array($key, ['pagination', 'expanded'], true)) {
                throw new InvalidArgumentException('Required UI property: ' . $component . '.' . $key);
            }
        }
        $enums = [
            'tone' => $component === 'feedback.notice' ? ['info', 'success', 'warning', 'danger'] : ['neutral', 'info', 'success', 'warning', 'danger', 'permanent'],
            'variant' => $component === 'layout.surface' ? ['standard', 'compact'] : ['primary', 'secondary', 'quiet', 'danger'],
            'size' => $component === 'overlay.modal' ? ['normal', 'wide'] : ($component === 'feedback.badge' ? ['small', 'normal'] : ($component === 'feedback.progress' ? ['normal', 'large'] : ['small', 'normal', 'large'])),
            'type' => ['button', 'submit', 'reset'], 'method' => ['get', 'post'],
            'columns' => $component === 'form.grid' ? ['auto', 1, 2] : ['auto', 1, 2, 3, 4],
            'heading_level' => [2, 3], 'density' => ['normal', 'compact'],
        ];
        if ($component === 'overlay.accordion-section') {
            $enums['workspace'] = ['', 'admin', 'account', 'company', 'library'];
        }
        foreach ($enums as $key => $allowed) {
            if (array_key_exists($key, $values) && !in_array($values[$key], $allowed, true)) {
                throw new InvalidArgumentException('Invalid semantic UI value: ' . $component . '.' . $key);
            }
        }
        if ($component === 'feedback.progress') {
            foreach (['min', 'max', 'value'] as $key) {
                if (!is_numeric($values[$key]) || !is_finite((float) $values[$key])) throw new InvalidArgumentException('Progress must be finite.');
                $values[$key] = (float) $values[$key];
            }
            if ($values['max'] <= $values['min']) throw new InvalidArgumentException('Invalid progress range.');
            $values['value'] = max($values['min'], min($values['max'], $values['value']));
        }
        if ($component === 'icon' && (preg_match('/^[a-z][a-z0-9-]*$/', (string) $values['name']) !== 1 || (!$values['decorative'] && !$values['label']))) {
            throw new InvalidArgumentException('Icons need a sprite name and meaningful icons need a label.');
        }
        return $values;
    }

    /** Render scalar/structured properties; variable HTML belongs in authored Twig blocks.
     * @param array<string,mixed> $context
     * @param array<string,mixed> $properties
     */
    public function render(Environment $twig, array $context, string $component, array $properties = []): Markup
    {
        $behaviour = array_intersect_key($context, array_flip(['csrf', 'can_favourite', 'favourite_return']));
        return new Markup($twig->render($this->template($component), ['props' => $this->properties($component, $properties)] + $behaviour), 'UTF-8');
    }
}
