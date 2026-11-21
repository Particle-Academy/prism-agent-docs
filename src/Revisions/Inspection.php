<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Revisions;

/**
 * What a TrackedChangesGuard found.
 *
 * A document that could not be inspected is refused like one with tracked
 * changes, because "could not tell" is not "none". The one exception is bytes
 * that are not an archive at all: no reader can open them either, so they go
 * on to the reader, which refuses them in its own fixed words
 * (DocsResult::UNREADABLE) and reports the detail to the host.
 */
final readonly class Inspection
{
    public const string CLEAN = 'clean';

    public const string TRACKED = 'tracked';

    public const string UNCHECKABLE = 'uncheckable';

    public const string NOT_AN_ARCHIVE = 'not_an_archive';

    /**
     * @param  ?string  $element  the revision element found (`ins`, `rPrChange`, ...)
     * @param  ?string  $part  the archive entry it was found in (`word/header1.xml`)
     * @param  ?string  $reason  why it could not be inspected (`too_many_entries`, `part_too_large`, ...): for the host's logs, never the person's message
     */
    private function __construct(
        public string $status,
        public ?string $element = null,
        public ?string $part = null,
        public ?string $reason = null,
    ) {}

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function tracked(string $element, string $part): self
    {
        return new self(self::TRACKED, $element, $part);
    }

    public static function uncheckable(string $reason): self
    {
        return new self(self::UNCHECKABLE, reason: $reason);
    }

    public static function notAnArchive(): self
    {
        return new self(self::NOT_AN_ARCHIVE, reason: self::NOT_AN_ARCHIVE);
    }

    /** Whether the edit must stop here, before the document is read. */
    public function refuses(): bool
    {
        return $this->status === self::TRACKED || $this->status === self::UNCHECKABLE;
    }
}
