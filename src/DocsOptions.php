<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use InvalidArgumentException;

/**
 * Host policy for one edit. Everything here is the HOST's to set: none of it
 * is reachable from the model, so a model cannot, for instance, declare an
 * empty document acceptable.
 */
final readonly class DocsOptions
{
    /** last-word's own default for `maxImages`. */
    public const int MAX_IMAGES = 32;

    /** 16 KiB: room for a long request, not for a pasted document. */
    public const int MAX_REQUEST_BYTES = 16384;

    /**
     * @param  bool  $allowEmpty  accept a draft with no text and no picture anywhere (the person asked for a blank page)
     * @param  array<string, mixed>  $images  extra pictures the host offers by name (a new logo), as last-word's `images` option takes them
     * @param  array<string, int>  $imageLimits  last-word `imageLimits` overrides for `maxBytes`, `maxWidthPx` and `maxHeightPx`; the count is `maxImages`
     * @param  bool  $freshConversation  start each edit on a new thread, so an earlier turn's copy of the document cannot stand in for the working copy
     * @param  int  $maxImages  the most distinct pictures an edit may hold: the working copy's own plus the host's. A file with more is refused, not accommodated
     * @param  int  $maxRequestBytes  the longest request accepted, in bytes; a longer one is refused before any model call
     */
    public function __construct(
        public bool $allowEmpty = false,
        public array $images = [],
        public array $imageLimits = [],
        public bool $freshConversation = true,
        public int $maxImages = self::MAX_IMAGES,
        public int $maxRequestBytes = self::MAX_REQUEST_BYTES,
    ) {
        if ($maxImages < 1) {
            throw new InvalidArgumentException('maxImages must be at least 1.');
        }
        if ($maxRequestBytes < 1) {
            throw new InvalidArgumentException('maxRequestBytes must be at least 1.');
        }
        if (array_key_exists('maxImages', $imageLimits)) {
            // One knob, so the cap the agent enforces and the one the writer
            // applies cannot disagree.
            throw new InvalidArgumentException('Set the picture count with DocsOptions::$maxImages, not imageLimits.maxImages.');
        }
    }
}
