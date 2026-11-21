<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

use InvalidArgumentException;
use Prism\AgentDocs\Support\DocumentTree;
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

        // Doing the doable part must never apply an offered alternative: no
        // path any limit offers may be one a limit asks the draft to set.
        $offered = array_merge([], ...array_values(array_map(static fn (Limit $limit): array => $limit->alternativePaths, $limits)));
        foreach ($limits as $limit) {
            foreach ($limit->expressible as $part) {
                if (in_array($part->path, $offered, true)) {
                    throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" lists "%s" as expressible, but a limit offers it as an alternative, which is never applied.', $limit->id, $part->path));
                }
            }
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
            $lines[] = sprintf('- `%s` (%s): %s', $limit->id, $limit->isWriterGap() ? 'a writer gap: Word can do this, last-word does not write it yet' : 'a format limit: no .docx can hold this', $limit->statement);
            $lines[] = sprintf('  Applies when: %s', $limit->appliesWhen);
            foreach ($limit->alternatives as $i => $alternative) {
                $lines[] = sprintf('  Alternative %d%s: %s', $i, $i === 0 ? ' (closest)' : '', $alternative);
            }
            foreach ($limit->expressible as $part) {
                $lines[] = sprintf('  Can still be done, when the request asks for it (for example "%s") and the document has a %s: set `%s` to %s. %s Without a %s it changes nothing anyone sees, so do not write it.', $part->askedBy[0], implode(' or ', $part->bands), $part->path, (string) json_encode($part->value), $part->instruction, $part->bands[0]);
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

        $cited = self::sources($sources, $id);

        $kind = $entry['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, Limit::KINDS, true)) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" needs a kind: "format" when no .docx can hold it, "writer" when Word can and last-word does not write it yet.', $id));
        }
        if ($kind === Limit::WRITER && ! self::saysNotYet($statement)) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" is a writer gap, so its statement must say this writer does not do it yet ("This writer does not yet ..."), and never that Word or the format cannot.', $id));
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

        $parts = $entry['expressible'] ?? [];
        if (! is_array($parts) || ! array_is_list($parts)) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has an "expressible" that is not a list.', $id));
        }
        $expressible = array_map(static fn (mixed $part): Expressible => self::expressible($part, $id), $parts);

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
            $expressible,
            $kind,
        );
    }

    /**
     * A writer gap's statement: about this writer, and not final. It starts
     * "This writer does not yet", and names neither Word nor the format, nor
     * calls anything impossible, since Word can do it.
     */
    private static function saysNotYet(string $statement): bool
    {
        return str_starts_with($statement, 'This writer does not yet ')
            && preg_match('/\bWord\b|\.docx|\bformat\b|impossible|\bcannot\b|\bcan\'t\b|\bnever\b/i', $statement) !== 1;
    }

    /**
     * @param  array<mixed>  $sources
     * @return list<array{package: string, file: string, section: string, quote: string}>
     */
    private static function sources(array $sources, string $id): array
    {
        $cited = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['package'] ?? null) || ! is_string($source['file'] ?? null) || ! is_string($source['section'] ?? null) || ! is_string($source['quote'] ?? null)) {
                throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has a source without package, file, section and quote.', $id));
            }
            $cited[] = ['package' => $source['package'], 'file' => $source['file'], 'section' => $source['section'], 'quote' => $source['quote']];
        }

        return $cited;
    }

    private static function expressible(mixed $part, string $id): Expressible
    {
        if (! is_array($part)) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has an expressible part that is not an object.', $id));
        }
        $path = $part['path'] ?? null;
        $value = $part['value'] ?? null;
        $instruction = $part['instruction'] ?? null;
        $sources = $part['sources'] ?? null;
        if (! is_string($path) || preg_match('/^page(\.[A-Za-z]+)+$/', $path) !== 1 || ! (is_int($value) || is_float($value)) || ! is_string($instruction) || $instruction === '' || ! is_array($sources) || $sources === []) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has a malformed expressible part: it needs a dotted path under page, a number value, an instruction and at least one source.', $id));
        }
        $done = $part['done'] ?? null;
        if (! is_string($done) || ! str_ends_with($done, '.') || str_contains($done, '`')) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has an expressible part for "%s" without a "done" sentence: one plain sentence, ending with a full stop, that tells the person what the draft did.', $id, $path));
        }
        $askedBy = self::strings($part['askedBy'] ?? null, $id, 'expressible.askedBy');
        $bands = self::strings($part['bands'] ?? null, $id, 'expressible.bands');
        if ($askedBy === [] || $bands === [] || array_diff($bands, DocumentTree::BANDS) !== []) {
            throw new RuntimeException(sprintf('[prism-agent-docs] The limit "%s" has an expressible part for "%s" without askedBy phrases, or without bands from: %s.', $id, $path, implode(', ', DocumentTree::BANDS)));
        }

        return new Expressible($path, $value, $instruction, $askedBy, $bands, self::sources($sources, $id), $done);
    }
}
