<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

use Prism\AgentDocs\Support\Paths;

/**
 * One thing a last-word .docx cannot express, with what to offer instead and where last-word says so.
 *
 * `kind` says whose limit it is: FORMAT when no .docx can hold it, WRITER when
 * Word can save it and last-word does not write it yet. A writer limit's
 * statement never says Word or the format cannot do it (the catalogue refuses
 * one that does): telling a person something is impossible when it is a gap
 * in this writer is the defect Fancy's parity matrix found.
 */
final readonly class Limit
{
    /** No .docx can hold it. */
    public const string FORMAT = 'format';

    /** Word can save it; last-word does not write it yet. */
    public const string WRITER = 'writer';

    public const array KINDS = [self::FORMAT, self::WRITER];

    /**
     * @param  list<string>  $alternatives  closest first
     * @param  list<array{package: string, file: string, section: string, quote: string}>  $sources
     * @param  list<string>  $alternativePaths  the document paths its alternatives would change (`page.margins.left`): OFFERED, never applied, so a draft's change to them is reverted while this limit is reported
     * @param  list<string>  $askedBy  the phrases that make a request ask for that change itself, so it is applied (see Support\Asked)
     * @param  ?string  $applied  what the person is told when the request asked for the change and the draft makes it
     * @param  list<Expressible>  $expressible  the parts of such a request a .docx CAN do: drafted even while this limit is reported, when the request asks for them
     * @param  string  $kind  self::FORMAT or self::WRITER
     * @param  list<?array<string, int|float>>  $alternativeChanges  per alternative, in the same order: the values it would set (`{"page.margins.left": 0}`), `{}` for one that changes nothing ("keep it as it is now"), or null for one that moves content and has no single value to compare
     */
    public function __construct(
        public string $id,
        public string $statement,
        public string $appliesWhen,
        public array $alternatives,
        public array $sources,
        public ?string $council = null,
        public array $alternativePaths = [],
        public array $askedBy = [],
        public ?string $applied = null,
        public array $expressible = [],
        public string $kind = self::FORMAT,
        public array $alternativeChanges = [],
    ) {}

    /**
     * Whether an alternative is what the document already is, so offering it
     * offers nothing (G-316 live run 01a2007d, S4 and R2: "keep the header art
     * inside the margins, as it is now" offered on a letterhead whose art
     * already was). One that changes nothing always is; one that sets values
     * is when the document already holds every one of them (numbers by value,
     * absent never); one that moves content never is.
     *
     * @param  array<mixed>  $document
     */
    public function isCurrentState(int $alternative, array $document): bool
    {
        $changes = $this->alternativeChanges[$alternative] ?? null;
        if ($changes === null) {
            return false;
        }
        foreach ($changes as $path => $value) {
            [$has, $held] = Paths::get($document, $path);
            if (! $has || ! (is_int($held) || is_float($held)) || (float) $held !== (float) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * The alternative to offer for this document: the one asked for unless it
     * is the current state, else the closest that is not, else none. Decided
     * here, in code, whatever number the model chose.
     *
     * @param  array<mixed>  $document
     */
    public function offer(int $chosen, array $document): ?int
    {
        if (isset($this->alternatives[$chosen]) && ! $this->isCurrentState($chosen, $document)) {
            return $chosen;
        }
        foreach (array_keys($this->alternatives) as $i) {
            if (! $this->isCurrentState($i, $document)) {
                return $i;
            }
        }

        return null;
    }

    public function isWriterGap(): bool
    {
        return $this->kind === self::WRITER;
    }
}
