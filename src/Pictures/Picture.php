<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Pictures;

/** One picture found in the source document, and the name it was registered under. */
final readonly class Picture
{
    public function __construct(
        public string $name,
        /** The part that holds it: `/blocks` (the body) or the band's JSON Pointer, e.g. `/page/header`. */
        public string $part,
        /** The image block's JSON Pointer. */
        public string $path,
        public string $sha256,
        public int $bytes,
        /** `png` or `jpeg`. */
        public string $type,
    ) {}
}
