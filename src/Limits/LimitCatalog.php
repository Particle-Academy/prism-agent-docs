<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

use InvalidArgumentException;
use RuntimeException;

/**
 * last-word's documented limits, as data.
 *
 * Loaded from resources/limits.json, where each entry quotes the last-word
 * document that states it. The agent is told these and nothing else: a limit
 * the model guesses at is a limit nobody can check, and G-316 scenario 3 is a
 * request the format really cannot serve, answered with a draft that changed
 * nothing. The model may only report a limit by an id from this catalog, and
 * the person is told the catalog's words, not the model's.
 */
final readonly class LimitCatalog
{
    /** @param array<string, Limit> $limits */
    private function __construct(private array $limits) {}

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__, 2).'/resources/limits.json';
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limits catalog is missing: %s', $path));
        }
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['limits'] ?? null)) {
            throw new RuntimeException('[prism-agent-docs] The limits catalog has no "limits" list.');
        }

        $limits = [];
        foreach ($data['limits'] as $entry) {
            $limit = self::entry($entry);
            if (isset($limits[$limit->id])) {
                throw new RuntimeException(sprintf('[prism-agent-docs] The limits catalog lists "%s" twice.', $limit->id));
            }
            $limits[$limit->id] = $limit;
        }

        return new self($limits);
    }

    /** @return array<string, Limit> */
    public function all(): array
    {
        return $this->limits;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->limits);
    }

    public function has(string $id): bool
    {
        return isset($this->limits[$id]);
    }

    public function get(string $id): Limit
    {
        return $this->limits[$id] ?? throw new InvalidArgumentException(sprintf('No limit "%s" is in the catalog.', $id));
    }

    /** The limits as the model is shown them. */
    public function forPrompt(): string
    {
        $lines = [];
        foreach ($this->limits as $limit) {
            $lines[] = sprintf('- `%s`: %s', $limit->id, $limit->statement);
            $lines[] = sprintf('  Applies when: %s', $limit->appliesWhen);
            foreach ($limit->alternatives as $i => $alternative) {
                $lines[] = sprintf('  Alternative %d%s: %s', $i, $i === 0 ? ' (closest)' : '', $alternative);
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private static function strings(mixed $value, string $id, string $key): array
    {
        if (! is_array($value) || array_filter($value, static fn (mixed $item): bool => ! is_string($item) || $item === '') !== []) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has a "%s" that is not a list of strings.', $id, $key));
        }

        /** @var list<string> */
        return array_values($value);
    }

    private static function entry(mixed $entry): Limit
    {
        if (! is_array($entry)) {
            throw new RuntimeException('[prism-agent-docs] A limits catalog entry is not an object.');
        }
        $id = $entry['id'] ?? null;
        $statement = $entry['statement'] ?? null;
        $applies = $entry['appliesWhen'] ?? null;
        $alternatives = $entry['alternatives'] ?? null;
        $sources = $entry['sources'] ?? null;
        if (! is_string($id) || ! is_string($statement) || ! is_string($applies) || ! is_array($alternatives) || $alternatives === [] || ! is_array($sources) || $sources === []) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limits catalog entry "%s" is malformed: it needs an id, a statement, appliesWhen, at least one alternative and at least one source.', is_string($id) ? $id : '?'));
        }

        $cited = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['package'] ?? null) || ! is_string($source['file'] ?? null) || ! is_string($source['section'] ?? null) || ! is_string($source['quote'] ?? null)) {
                throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has a source without package, file, section and quote.', $id));
            }
            $cited[] = ['package' => $source['package'], 'file' => $source['file'], 'section' => $source['section'], 'quote' => $source['quote']];
        }

        $council = $entry['council'] ?? null;

        $paths = self::strings($entry['alternativePaths'] ?? [], $id, 'alternativePaths');
        $askedBy = self::strings($entry['askedBy'] ?? [], $id, 'askedBy');
        $applied = $entry['applied'] ?? null;
        foreach ($paths as $path) {
            if (preg_match('/^page(\.[A-Za-z]+)+$/', $path) !== 1) {
                throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" names the path "%s"; an alternative path is a dotted path under page, such as page.margins.left.', $id, $path));
            }
        }
        if ($paths !== [] && ! is_string($applied)) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has alternativePaths but no "applied" wording for when the request asks for that change.', $id));
        }

        return new Limit(
            $id,
            $statement,
            $applies,
            array_values(array_filter($alternatives, is_string(...))),
            $cited,
            is_string($council) ? $council : null,
            $paths,
            $askedBy,
            is_string($applied) ? $applied : null,
        );
    }
}
