<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

/**
 * Host policy for one edit. Everything here is the HOST's to set: none of it
 * is reachable from the model, so a model cannot, for instance, declare an
 * empty document acceptable.
 */
final readonly class DocsOptions
{
    /**
     * @param  bool  $allowEmpty  accept a draft with no text and no picture anywhere (the person asked for a blank page)
     * @param  array<string, mixed>  $images  extra pictures the host offers by name (a new logo), as last-word's `images` option takes them
     * @param  array<string, int>  $imageLimits  last-word `imageLimits` overrides; `maxImages` defaults to what the saved file needs, never below last-word's 32
     * @param  bool  $freshConversation  start each edit on a new thread, so an earlier turn's copy of the document cannot stand in for the saved file
     */
    public function __construct(
        public bool $allowEmpty = false,
        public array $images = [],
        public array $imageLimits = [],
        public bool $freshConversation = true,
    ) {}
}
