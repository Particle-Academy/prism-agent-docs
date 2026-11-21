<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

/**
 * Where the document lives, as the host keeps it.
 *
 * Two slots, because a host that edits documents almost always has both: the
 * file the person SAVED, and a pending draft an earlier run proposed and nobody
 * has accepted yet. They are kept apart here on purpose. The agent reads only
 * the saved file, and only ever writes the draft: it never overwrites what the
 * person saved, and it never starts from a draft (G-316 scenario 2, where an
 * empty pending draft was handed to the writer as "the document").
 */
interface DocumentStore
{
    /** The saved .docx bytes, or null when there is no file yet (the agent creates one). */
    public function saved(): ?string;

    /** The pending draft's .docx bytes, or null when there is none. Never read by the agent. */
    public function draft(): ?string;

    /** Replace the pending draft. Called once per run, and only with a draft that passed the self-check. */
    public function putDraft(string $bytes): void;
}
