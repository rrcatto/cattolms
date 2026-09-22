<?php

declare(strict_types=1);

namespace CattoLearning\Course;

/** Resolves stored Course Item source at display time and delegates output to bounded type renderers. */
final class CourseItemRenderer
{
    public function __construct(private readonly CourseItemRepository $items, private readonly ResourceLibraryService $resources)
    {
    }

    /**
     * @param array<string,mixed> $item
     * @param list<string> $stack
     */
    public function render(array $item, string $courseSlug, int $contextNodeId, bool $publicPreview = false, array $stack = []): string
    {
        $key = (string) ($item['item_key'] ?? '');
        if (in_array($key, $stack, true)) {
            return '<aside class="cl-course-item-error">Circular Course Item reference: ' . self::escape($key) . '</aside>';
        }
        $stack[] = $key;
        $description = (string) ($item['description_html'] ?? '');
        $config = is_array($item['type_config'] ?? null) ? $item['type_config'] : [];
        return match ((string) ($item['item_type'] ?? '')) {
            'html_lesson' => $this->renderHtml((string) ($item['content_source'] ?? ''), $courseSlug, $contextNodeId, $publicPreview, $stack),
            'image_graphic' => '<figure class="cl-course-item-media"><img src="' . $this->resourceUrl($item) . '" alt="' . self::escape((string) ($item['resource_description'] ?? '')) . '"><figcaption>' . $description . self::escape((string) ($config['caption'] ?? '')) . '</figcaption></figure>',
            'youtube' => '<figure class="cl-course-item-media"><iframe src="https://www.youtube-nocookie.com/embed/' . rawurlencode((string) ($config['youtube_id'] ?? '')) . '" title="' . self::escape((string) ($item['title'] ?? 'Video')) . '" allowfullscreen></iframe><figcaption>' . $description . self::escape((string) ($config['caption'] ?? '')) . '</figcaption></figure>' . $this->transcript($config),
            'uploaded_video' => '<figure class="cl-course-item-media"><video controls src="' . $this->resourceUrl($item) . '"' . $this->poster($config) . '>' . $this->subtitles($config) . '</video><figcaption>' . $description . self::escape((string) ($config['caption'] ?? '')) . '</figcaption></figure>' . $this->transcript($config),
            'audio' => '<section class="cl-course-item-media"><audio controls src="' . ($item['resource_id'] ? $this->resourceUrl($item) : self::escape((string) ($config['remote_uri'] ?? ''))) . '"></audio>' . $description . '<p>' . self::escape((string) ($config['caption'] ?? '')) . '</p>' . $this->transcript($config) . '</section>',
            'pdf' => '<section class="cl-course-item-document"><object data="' . $this->resourceUrl($item) . '" type="application/pdf"><a href="' . $this->resourceUrl($item) . '">Open PDF</a></object><p><a href="' . $this->resourceUrl($item) . '" download>Download ' . self::escape((string) ($item['title'] ?? 'PDF')) . '</a></p>' . $description . '</section>',
            'markdown' => '<article class="cl-course-item-markdown">' . $this->markdown($this->resourceSource($item)) . '<p><a href="' . $this->resourceUrl($item) . '" download>Download source</a></p></article>',
            'document' => '<section class="cl-course-item-document">' . $description . '<p><a href="' . $this->resourceUrl($item) . '" download>Download ' . self::escape((string) ($item['title'] ?? 'document')) . '</a></p></section>',
            'assessment','diagnostic' => '<section class="cl-course-item-assessment"><h2>' . self::escape((string) ($item['title'] ?? $item['item_title'] ?? 'Assessment')) . '</h2>' . $description . '<p><a href="' . ($publicPreview ? '/courses/' . rawurlencode($courseSlug) . '/preview/' : '/learn/' . rawurlencode($courseSlug) . '/item/') . $contextNodeId . '/assessment/' . rawurlencode($key) . '">' . ($publicPreview ? 'Try assessment' : 'Open assessment') . '</a></p></section>',
            default => '<aside class="cl-course-item-error">Unsupported Course Item type.</aside>',
        };
    }

    /** @param list<string> $stack */
    private function renderHtml(string $source, string $slug, int $contextNodeId, bool $public, array $stack): string
    {
        return (string) preg_replace_callback('/\[course-item:([a-zA-Z0-9][a-zA-Z0-9._-]{0,119})\]/', function (array $match) use ($slug, $contextNodeId, $public, $stack): string {
            $item = $this->items->itemByKey((string) $match[1]);
            return $item === null ? '<aside class="cl-course-item-error">Unresolved Course Item: ' . self::escape((string) $match[1]) . '</aside>' : $this->render($item, $slug, $contextNodeId, $public, $stack);
        }, $source);
    }

    /** @param array<string,mixed> $config */
    private function poster(array $config): string
    {
        $resource = $this->items->resource((int) ($config['poster_resource_id'] ?? 0));
        return $resource === null ? '' : ' poster="/course-resources/' . rawurlencode((string) $resource['public_id']) . '"';
    }

    /** @param array<string,mixed> $config */
    private function subtitles(array $config): string
    {
        $resource = $this->items->resource((int) ($config['subtitle_resource_id'] ?? 0));
        return $resource === null ? '' : '<track kind="captions" src="/course-resources/' . rawurlencode((string) $resource['public_id']) . '" label="Captions" default>';
    }

    /** @param array<string,mixed> $item */
    private function resourceUrl(array $item): string { return '/course-resources/' . rawurlencode((string) ($item['resource_public_id'] ?? '')); }
    /** @param array<string,mixed> $item */
    private function resourceSource(array $item): string
    {
        $resource = $this->items->resource((int) ($item['resource_id'] ?? 0));
        if ($resource === null) { return ''; }
        $path = $this->resources->path($resource);
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
    /** @param array<string,mixed> $config */
    private function transcript(array $config): string { $value = trim((string) ($config['transcript'] ?? '')); return $value === '' ? '' : '<details><summary>Transcript</summary>' . $value . '</details>'; }

    private function markdown(string $source): string
    {
        $lines = preg_split('/\R/', $source) ?: []; $html = []; $list = ''; $code = null;
        /** @var list<string> $paragraph */
        $paragraph = [];
        $flushParagraph = static function () use (&$html, &$paragraph): void { if ($paragraph !== []) { $html[] = '<p>' . implode(' ', $paragraph) . '</p>'; $paragraph = []; } };
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $flushParagraph();
                if ($list !== '') { $html[] = '</' . $list . '>'; $list = ''; }
                if ($code === null) { $code = []; } else { $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>'; $code = null; }
                continue;
            }
            if ($code !== null) { $code[] = $line; continue; }
            $isList = preg_match('/^\s*(?:([-+*])|\d+[.)])\s+(.+)$/', $line, $match) === 1;
            if (!$isList && $list !== '') { $html[] = '</' . $list . '>'; $list = ''; }
            if ($isList) {
                $flushParagraph(); $kind = $match[1] !== '' ? 'ul' : 'ol';
                if ($list !== $kind) { if ($list !== '') { $html[] = '</' . $list . '>'; } $html[] = '<' . $kind . '>'; $list = $kind; }
                $html[] = '<li>' . $this->markdownInline($match[2]) . '</li>';
            } elseif (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match) === 1) {
                $flushParagraph(); $level = strlen($match[1]); $html[] = '<h' . $level . '>' . $this->markdownInline($match[2]) . '</h' . $level . '>';
            } elseif (preg_match('/^>\s?(.*)$/', $line, $match) === 1) {
                $flushParagraph(); $html[] = '<blockquote>' . $this->markdownInline($match[1]) . '</blockquote>';
            } elseif (trim($line) === '') { $flushParagraph(); }
            else { $paragraph[] = $this->markdownInline($line); }
        }
        $flushParagraph();
        if ($list !== '') { $html[] = '</' . $list . '>'; }
        if ($code !== null) { $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>'; }
        return implode("\n", $html);
    }

    private function markdownInline(string $source): string
    {
        $escaped = self::escape($source);
        $escaped = preg_replace('/\[([^\]]+)\]\(([^\s)]+)\)/', '<a href="$2">$1</a>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
        return preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    }

    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
