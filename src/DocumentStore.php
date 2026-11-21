<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

/**
 * Where the document lives, as the host keeps it.
 *
 * Two slots, because a host that edits documents almost always has both: the
 * file the person SAVED, and a pending draft an earlier run proposed and nobody
 * has accepted yet. The agent never writes the saved file; it only replaces
 * the draft.
 *
 * Which of the two an edit revises is WorkingCopy::fromStore()'s policy, not
 * a choice the model makes: the pending draft when it holds any text or
 * picture, otherwise the saved file. An empty pending draft is never a
 * starting point (G-316 scenario 2). A host that wants another policy passes
 * DocsAgent::edit() a WorkingCopy of its own instead of a store.
 */
interface DocumentStore
{
    /** The saved .docx bytes, or null when there is no file yet (the agent creates one). */
    public function saved(): ?string;

    /** The pending draft's .docx bytes, or null when there is none. */
    public function draft(): ?string;

    /** Replace the pending draft. Called once per run, and only with a draft that passed the self-check. */
    public function putDraft(string $bytes): void;
}
