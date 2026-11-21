<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use InvalidArgumentException;
use LastWord\Agent;
use Prism\AgentDocs\Support\DocumentTree;

/**
 * The document an edit revises, as the HOST chose it.
 *
 * The agent never picks between the saved file and a pending draft: the host
 * hands it one document, and that is the one revised. `baseVersion` is the
 * host's opaque name for the SAVED version these bytes derive from. The agent
 * does not interpret it; it carries it through to DocsResult::$baseVersion, so
 * the host can refuse to save a draft once the saved file has moved past it.
 * The agent never saves, so that refusal is the host's to make.
 *
 * Hosts that keep a DocumentStore get a default, fromStore(): the pending
 * draft when it holds something, otherwise the saved file.
 */
final readonly class WorkingCopy
{
    /** The base version fromStore() gives when nothing is saved yet. */
    public const string NOTHING_SAVED = 'none';

    public ?string $bytes;

    /**
     * @param  ?string  $bytes  the .docx to revise, or null (or '') to create one
     * @param  string  $baseVersion  the host's identifier of the saved version these bytes derive from
     */
    public function __construct(?string $bytes, public string $baseVersion)
    {
        if ($baseVersion === '') {
            throw new InvalidArgumentException('A working copy needs a baseVersion: the host\'s identifier of the saved version it derives from.');
        }
        $this->bytes = $bytes === '' ? null : $bytes;
    }

    /**
     * The default policy for a DocumentStore: continue the pending draft when
     * it holds any text or picture (body, header or footer), otherwise start
     * from the saved file. An empty draft is never a starting point (G-316
     * scenario 2); a non-empty one is what the person is looking at, so "now
     * make the logo bigger" revises it.
     *
     * The base version is always the SAVED file's, by versionOf(), because a
     * draft derives from the saved file it was made from.
     *
     * @throws \Throwable when the pending draft cannot be read; it is not silently passed over
     */
    public static function fromStore(DocumentStore $store): self
    {
        $saved = $store->saved();
        $version = self::versionOf($saved);

        $draft = $store->draft();
        if ($draft !== null && $draft !== '' && self::holdsSomething($draft)) {
            return new self($draft, $version);
        }

        return new self($saved, $version);
    }

    /**
     * The base version fromStore() gives a saved file: its sha256, or
     * NOTHING_SAVED. A host using the default compares this against the file
     * it holds when the person accepts the draft.
     */
    public static function versionOf(?string $saved): string
    {
        return $saved === null || $saved === '' ? self::NOTHING_SAVED : 'sha256:'.hash('sha256', $saved);
    }

    public function exists(): bool
    {
        return $this->bytes !== null;
    }

    private static function holdsSomething(string $docx): bool
    {
        $read = Agent::read($docx);
        unset($read['unrepresented'], $read['sourceKind']);

        return DocumentTree::hasContent($read);
    }
}
