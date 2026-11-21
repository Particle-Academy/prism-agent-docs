<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Pictures;

final readonly class RegisteredPictures
{
    /**
     * @param  array<string, mixed>  $named  the document with every picture as `image:NAME`
     * @param  array<string, string>  $images  name => bytes, ready for last-word's `images` option
     * @param  list<Picture>  $pictures  every occurrence, in document order
     */
    public function __construct(
        public array $named,
        public array $images,
        public array $pictures,
    ) {}
}
