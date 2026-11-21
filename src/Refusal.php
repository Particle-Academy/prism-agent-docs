<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

/**
 * Why an edit was refused before any model call, for a host to act on
 * without parsing the message. On DocsResult::$refusal, with Outcome::Failed.
 * Null on every other failure.
 */
enum Refusal: string
{
    /** The working copy holds tracked changes, which last-word's reader would silently accept. */
    case TrackedChanges = 'tracked_changes';

    /** The working copy could not be checked for tracked changes (too large, too many parts, malformed), so it was not read. */
    case Uncheckable = 'uncheckable';
}
