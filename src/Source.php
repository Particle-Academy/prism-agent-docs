<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use LastWord\Agent;
use Prism\AgentDocs\Pictures\Picture;
use Prism\AgentDocs\Pictures\PictureRegistry;

/**
 * The document the agent starts from: always the SAVED file.
 *
 * G-316 scenario 2: a pending draft that held nothing was handed to the writer
 * as the document, so the writer revised nothing into nothing. The saved file
 * is the only thing a person has agreed to; a draft is a proposal. So this is
 * built from DocumentStore::saved() and never calls draft(). The draft slot is
 * written at the end of a run, which is what replaces an empty one.
 */
final readonly class Source
{
    /**
     * @param  ?string  $bytes  the saved .docx, or null when creating
     * @param  array<string, mixed>  $read  the saved document as last-word reads it, pictures as data URLs
     * @param  array<string, mixed>  $named  the same document with every picture as `image:NAME`
     * @param  array<string, string>  $images  name => bytes, for every picture in the saved file
     * @param  list<Picture>  $pictures  every picture occurrence, in document order
     * @param  list<array<mixed>>  $unrepresented  what last-word could not carry from the file
     */
    public function __construct(
        public ?string $bytes,
        public array $read,
        public array $named,
        public array $images,
        public array $pictures,
        public array $unrepresented,
    ) {}

    public static function fromStore(DocumentStore $store): self
    {
        $bytes = $store->saved();
        if ($bytes === null || $bytes === '') {
            return new self(null, ['blocks' => []], ['blocks' => []], [], [], []);
        }

        $read = Agent::read($bytes);
        $unrepresented = is_array($read['unrepresented'] ?? null) ? array_values(array_filter($read['unrepresented'], is_array(...))) : [];
        unset($read['unrepresented'], $read['sourceKind']);

        $registered = PictureRegistry::register($read);

        return new self($bytes, $read, $registered->named, $registered->images, $registered->pictures, $unrepresented);
    }

    public function exists(): bool
    {
        return $this->bytes !== null;
    }
}
