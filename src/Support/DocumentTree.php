<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Support;

use Closure;

/**
 * Walks a last-word document model as a tree.
 *
 * Deliberately GENERIC: it visits every array in the document rather than the
 * places a picture is known to live today (body blocks, table cells, quotes,
 * footnotes, the six page bands, a page break's own bands). G-316 scenario 1
 * happened because the host enumerated pictures by place and the place it
 * forgot was the page header. A walk that does not enumerate places cannot
 * forget one, including a place last-word adds later.
 *
 * Every picture is attributed to a PART: `/blocks` for the body, or the JSON
 * Pointer of the band that holds it (`/page/header`, `/blocks/7/page/footer`).
 * The self-check compares pictures part by part, so a logo that moved from the
 * header into the body is a missing header picture, not a match.
 */
final class DocumentTree
{
    /** The page bands last-word knows, in the order a reader thinks of them. */
    public const array BANDS = ['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'];

    public const string BODY = '/blocks';

    /**
     * Every image block, wherever it is.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, part: string, src: string}>
     */
    public static function images(array $doc): array
    {
        $found = [];
        self::mapImages($doc, static function (string $src, string $part, string $path) use (&$found): string {
            $found[] = ['path' => $path, 'part' => $part, 'src' => $src];

            return $src;
        });

        return $found;
    }

    /**
     * The document with each image block's `src` replaced by what $map returns.
     *
     * @param  array<string, mixed>  $doc
     * @param  Closure(string $src, string $part, string $path): string  $map
     * @return array<string, mixed>
     */
    public static function mapImages(array $doc, Closure $map): array
    {
        foreach ($doc as $key => $value) {
            if (is_array($value)) {
                $doc[$key] = self::walk($value, '/'.self::escape($key), self::BODY, $key === 'page', $map);
            }
        }

        return $doc;
    }

    /**
     * Whether the document holds anything a person would see: text anywhere
     * (body, any band, a band slot), a picture or a chart. A page-number field
     * counts, because a footer holding `{page}` is not an empty footer.
     *
     * @param  array<mixed>  $node
     */
    public static function hasContent(array $node): bool
    {
        $type = $node['type'] ?? null;
        if ($type === 'image' || $type === 'chart') {
            return true;
        }
        foreach ($node as $key => $value) {
            if (is_string($value) && trim($value) !== '' && in_array($key, ['text', 'left', 'center', 'right'], true)) {
                return true;
            }
            if (is_array($value) && self::hasContent($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $node
     * @param  Closure(string, string, string): string  $map
     * @return array<mixed>
     */
    private static function walk(array $node, string $path, string $part, bool $inPage, Closure $map): array
    {
        if (($node['type'] ?? null) === 'image' && is_string($node['src'] ?? null)) {
            $node['src'] = $map($node['src'], $part, $path);
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            $childPath = $path.'/'.self::escape((string) $key);
            $childPart = $inPage && is_string($key) && in_array($key, self::BANDS, true) ? $childPath : $part;
            $node[$key] = self::walk($value, $childPath, $childPart, $key === 'page', $map);
        }

        return $node;
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
