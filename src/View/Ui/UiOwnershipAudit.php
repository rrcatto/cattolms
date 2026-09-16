<?php

declare(strict_types=1);

namespace CattoLearning\View\Ui;

/** Source-level guard for UI-producing code outside the canonical Twig component owners. */
final class UiOwnershipAudit
{
    private const OBSOLETE = '/^(?:btn(?:-[\w-]+)?|card(?:-body|-header)?|modal-card|stat-card|notice|badge|form-grid|row-actions|cl-ui-[\w-]+)$/';

    /** @return list<string> Actionable diagnostics; selectors and comments alone are harmless. */
    public static function javascript(string $source, string $file): array
    {
        $errors = [];
        // Lex strings before comments so URLs and comment-like text inside strings stay intact.
        preg_match_all('~"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27|`(?:\\\\.|[^`\\\\])*`|/\*.*?\*/|//[^\n]*~s', $source, $tokens, PREG_OFFSET_CAPTURE);
        $code = $source;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '//') || str_starts_with($token, '/*')) {
                $code = substr_replace($code, str_repeat(' ', strlen($token)), $offset, strlen($token));
                continue;
            }
            $literal = stripcslashes(substr($token, 1, -1));
            preg_match_all('/\bclass\s*=\s*["\x27]([^"\x27]*)["\x27]/', $literal, $classes);
            foreach ($classes[1] as $classList) {
                foreach (preg_split('/\s+/', $classList) ?: [] as $class) {
                    if (preg_match(self::OBSOLETE, $class)) $errors[] = $file . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1) . ': generated ' . $class . ' must come from a canonical Twig prototype.';
                }
            }
        }
        preg_match_all('/(?:\.className\s*=|\.classList\.(?:add|toggle)\(|\.setAttribute\(\s*["\x27]class["\x27]\s*,)\s*(["\x27`])([^"\x27`]+)\1/', $code, $assignments, PREG_SET_ORDER);
        foreach ($assignments as $assignment) {
            foreach (preg_split('/\s+/', $assignment[2]) ?: [] as $class) {
                if (preg_match(self::OBSOLETE, $class)) $errors[] = $file . ': generated class ' . $class . ' bypasses canonical Twig ownership.';
            }
        }
        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    public static function themeGeometry(string $css, string $file): array
    {
        $errors = [];
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        // Catch damaged blocks/selector functions before the browser silently discards them.
        $syntax = (string) preg_replace('/"(?:\\\\.|[^"\\\\])*"|\x27(?:\\\\.|[^\x27\\\\])*\x27/s', '""', $css);
        $stack = [];
        foreach (str_split($syntax) as $character) {
            if (in_array($character, ['{', '(', '['], true)) $stack[] = $character;
            elseif (isset(['}' => '{', ')' => '(', ']' => '['][$character])) {
                if (array_pop($stack) !== ['}' => '{', ')' => '(', ']' => '['][$character]) {
                    $errors[] = $file . ': unbalanced CSS block or selector.';
                    break;
                }
            }
        }
        if ($stack !== []) $errors[] = $file . ': unclosed CSS block or selector.';
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $rule) {
            if (!preg_match('/\.(?:cl-ui-[\w-]+|cl-course-card|pagination-[\w-]+|company-context(?:-[\w-]+)?|dataset-search(?:-[\w-]+)?)(?![\w-])/', $rule[1])) continue;
            // Split selector lists without splitting commas inside :is()/ :where(). Every
            // branch must be decoration; a mixed list must not exempt a functional element.
            $selectors = preg_split('/,(?![^(]*\))/', trim($rule[1])) ?: [];
            $decoration = $selectors !== [];
            foreach ($selectors as $selector) {
                $decoration = $decoration && (bool) preg_match('/\.cl-ui-section-head(?::[\w()-]+)*(?: h[1-6])?::(?:before|after)\s*$/', $selector);
            }
            $decoration = $decoration && (bool) preg_match('/\bcontent\s*:\s*(?:""|\x27\x27)\s*(?:;|$)/', $rule[2]);
            $absoluteDecoration = $decoration
                && preg_match('/\bposition\s*:\s*absolute\s*(?:;|$)/', $rule[2])
                && preg_match('/\bpointer-events\s*:\s*none\s*(?:;|$)/', $rule[2]);
            if ($decoration && !$absoluteDecoration) {
                $errors[] = $file . ': section-head decoration must be absolute and non-interactive, never a flex item.';
            }
            foreach (explode(';', $rule[2]) as $declaration) {
                $property = trim(explode(':', $declaration, 2)[0]);
                if ($absoluteDecoration && preg_match('/^(?:position|inset(?:-.+)?|top|right|bottom|left|z-index|width|height)$/', $property)) continue;
                if (preg_match('/^(display|position|inset(?:-.+)?|top|right|bottom|left|z-index|float|clear|overflow(?:-.+)?|flex(?:-.+)?|grid(?:-.+)?|align-.+|justify-.+|place-.+|order|width|min-width|max-width|height|min-height|max-height|visibility|white-space|box-sizing|touch-action)$/', $property)) {
                    $errors[] = $file . ': ' . trim($rule[1]) . ' sets core-owned ' . $property;
                }
            }
        }
        return $errors;
    }

    /** @return list<string> */
    public static function stimulusActions(string $twig, string $file): array
    {
        $twig = (string) preg_replace('/{#.*?#}/s', '', $twig);
        preg_match_all('/data-action="([^"]*)"/', $twig, $actions);
        foreach ($actions[1] as $action) {
            if (str_contains($action, '{%') || str_contains($action, 'aria-') || str_contains($action, '<')) {
                return [$file . ': malformed Stimulus data-action; field accessibility belongs in ui_field_attrs(props).'];
            }
        }
        return [];
    }
}
