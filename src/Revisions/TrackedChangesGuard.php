<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Revisions;

/**
 * Decides, before anything reads a working copy, whether it holds tracked
 * changes.
 *
 * Why it exists: last-word 0.21's reader silently ACCEPTS every tracked
 * change (inserted text is kept, deleted text is dropped, and nothing says
 * so). A draft built from that read and accepted by the person throws away
 * their reviewers' deletions and makes every insertion final. So a document
 * with tracked changes is refused, never read.
 *
 * The default is LastWordTrackedChanges: last-word's own signal
 * (`refuseTrackedChanges`, last-word 0.22.0) when the installed last-word has
 * it, and ScanForTrackedChanges always. A host binds its own in the container
 * or passes one to DocsAgent, with nothing else changing.
 */
interface TrackedChangesGuard
{
    /** Inspect a .docx. Never throws: what cannot be inspected comes back as Inspection::uncheckable(), and bytes that are not an archive as Inspection::notAnArchive(). */
    public function inspect(string $docx): Inspection;
}
