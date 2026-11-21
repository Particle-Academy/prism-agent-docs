<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Support;

/** Dotted paths into a document (`page.margins.left`), as the limits catalogue names them. */
final class Paths
{
    /**
     * @param  array<mixed>  $doc
     * @return array{0: bool, 1: mixed} whether the path is set, and its value
     */
    public static function get(array $doc, string $path): array
    {
        $node = $doc;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [false, null];
            }
            $node = $node[$segment];
        }

        return [true, $node];
    }

    /**
     * Whether two documents hold the same value at a path. Numbers compare by
     * value, so 72 and 72.0 are the same margin; absent equals only absent.
     *
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     */
    public static function same(array $a, array $b, string $path): bool
    {
        [$hasA, $valueA] = self::get($a, $path);
        [$hasB, $valueB] = self::get($b, $path);
        if ($hasA !== $hasB) {
            return false;
        }
        if (is_int($valueA) || is_float($valueA)) {
            return (is_int($valueB) || is_float($valueB)) && (float) $valueA === (float) $valueB;
        }

        return $valueA === $valueB;
    }

    /**
     * $doc with the value at $path taken from $from: set when $from has it,
     * removed when it does not. A parent emptied by the removal is removed
     * too, so reverting the only margin a draft wrote leaves no `margins: {}`.
     *
     * @param  array<string, mixed>  $doc
     * @param  array<mixed>  $from
     * @return array<string, mixed>
     */
    public static function copy(array $doc, array $from, string $path): array
    {
        [$has, $value] = self::get($from, $path);
        $segments = explode('.', $path);

        /** @var array<string, mixed> */
        return self::put($doc, $segments, $has, $value);
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $segments
     * @return array<mixed>
     */
    private static function put(array $node, array $segments, bool $has, mixed $value): array
    {
        $key = array_shift($segments);
        if ($key === null) {
            return $node;
        }
        if ($segments === []) {
            if ($has) {
                $node[$key] = $value;
            } else {
                unset($node[$key]);
            }

            return $node;
        }
        if (! is_array($node[$key] ?? null)) {
            if (! $has) {
                return $node;
            }
            $node[$key] = [];
        }
        /** @var array<mixed> $child */
        $child = $node[$key];
        $child = self::put($child, $segments, $has, $value);
        if ($child === [] && ! $has) {
            unset($node[$key]);
        } else {
            $node[$key] = $child;
        }

        return $node;
    }
}
