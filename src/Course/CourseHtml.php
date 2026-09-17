<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/** Owner-authored course content is trusted; this class only normalises course structure. */
final class CourseHtml
{
    public function preserve(string $html): string
    {
        return trim($html);
    }

    /** Remove the duplicated LMS heading, without filtering authored elements or attributes. */
    public function learningOutcomes(string $html): string
    {
        $html = $this->preserve($html);
        if ($html === '') return '';
        $previous = libxml_use_internal_errors(true);
        try {
            $document = HTMLDocument::createFromString('<!doctype html><html><body><div id="cl-outcomes-root">' . $html . '</div></body></html>', \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new XPath($document);
        $root = $xpath->query('//*[@id="cl-outcomes-root"]')->item(0);
        if (!$root instanceof Element) return $html;
        $candidates = $xpath->query('.//*[self::span or self::strong or self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $root);
        foreach ($candidates as $candidate) {
            $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $candidate->textContent) ?? $candidate->textContent));
            if (!in_array($label, ['learning outcomes', 'learning outcome'], true)) continue;
            $candidate->parentNode?->removeChild($candidate);
            $result = '';
            foreach ($root->childNodes as $child) $result .= $document->saveHtml($child);
            return trim($result);
        }
        return $html;
    }
}
