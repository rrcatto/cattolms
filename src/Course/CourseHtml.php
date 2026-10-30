<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/** Owner-authored course content is trusted; this class only normalises course structure. */
final class CourseHtml
{
    private const HTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const MATHML_NAMESPACE = 'http://www.w3.org/1998/Math/MathML';

    public function preserve(string $html): string
    {
        return trim($html);
    }

    /** Preserve the authored Learning Outcomes block, including its heading and styling hooks. */
    public function learningOutcomes(string $html): string
    {
        return $this->preserve($html);
    }

    /**
     * A node of a document parsed with Dom\HTML_NO_DEFAULT_NS (so that XPath finds elements by their plain names)
     * written back as the HTML it was (2026-10-30). Serialized directly, such a document's elements are not HTML
     * elements: a line break comes out as <br></br>, which a browser reads as two, and a script's or style's text is
     * escaped, which breaks it. The node is rebuilt as HTML elements first, so void elements, raw text, SVG and
     * MathML come out as authored.
     */
    public static function html(Node $node): string
    {
        $document = HTMLDocument::createEmpty();
        $holder = $document->createElement('div');
        $holder->appendChild(self::rebuild($node, $document));
        $html = '';
        foreach ($holder->childNodes as $child) { $html .= $document->saveHtml($child); }
        return $html;
    }

    /** The children of an element of such a document, written back as HTML. */
    public static function innerHtml(Element $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) { $html .= self::html($child); }
        return $html;
    }

    /**
     * The flag leaves every element without a namespace, SVG and MathML too, so each element's namespace is worked out
     * from where it sits, as the HTML parser does: svg and math open their own, and SVG's foreignObject, desc and
     * title hold HTML again.
     */
    private static function rebuild(Node $node, HTMLDocument $document, string $parent = self::HTML_NAMESPACE): Node
    {
        if (!$node instanceof Element) { return $document->importNode($node, false); }
        $name = $node->localName;
        $namespace = $node->namespaceURI ?? match (true) {
            $parent === self::HTML_NAMESPACE && $name === 'svg' => self::SVG_NAMESPACE,
            $parent === self::HTML_NAMESPACE && $name === 'math' => self::MATHML_NAMESPACE,
            default => $parent,
        };
        $copy = $namespace === self::HTML_NAMESPACE ? $document->createElement($name) : $document->createElementNS($namespace, $node->tagName);
        foreach ($node->attributes as $attribute) {
            if ($attribute->namespaceURI === null) { $copy->setAttribute($attribute->name, $attribute->value); }
            else { $copy->setAttributeNS($attribute->namespaceURI, $attribute->name, $attribute->value); }
        }
        if ($namespace === self::HTML_NAMESPACE && $name === 'template') {
            // A template's children belong in its content, which only its innerHTML reaches.
            $copy->innerHTML = self::innerHtml($node);
            return $copy;
        }
        $inside = $namespace === self::SVG_NAMESPACE && in_array($name, ['foreignObject', 'desc', 'title'], true) ? self::HTML_NAMESPACE : $namespace;
        foreach ($node->childNodes as $child) { $copy->appendChild(self::rebuild($child, $document, $inside)); }
        return $copy;
    }
}
