<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use LastWord\Agent;
use Prism\AgentDocs\Pictures\Picture;
use Prism\AgentDocs\Pictures\PictureRegistry;

/**
 * The document the agent starts from: the host's WORKING COPY, read.
 *
 * Which bytes those are is the host's decision, made in WorkingCopy, never the
 * agent's (G-316: an empty pending draft was once handed to the writer as "the
 * document"). Everything downstream measures against this: what a draft leaves
 * out is kept from it, a band the draft does not mention must equal its band,
 * and a draft identical to it changes nothing.
 */
final readonly class Source
{
    /**
     * @param  ?string  $bytes  the working copy's .docx, or null when creating
     * @param  array<string, mixed>  $read  the document as last-word reads it, pictures as data URLs
     * @param  array<string, mixed>  $named  the same document with every picture as `image:NAME`
     * @param  array<string, string>  $images  name => bytes, for every picture in the file
     * @param  list<Picture>  $pictures  every picture occurrence, in document order
     * @param  list<array<mixed>>  $unrepresented  what last-word could not carry from the file
     * @param  string  $baseVersion  the host's identifier of the saved version the working copy derives from
     */
    public function __construct(
        public ?string $bytes,
        public array $read,
        public array $named,
        public array $images,
        public array $pictures,
        public array $unrepresented,
        public string $baseVersion = WorkingCopy::NOTHING_SAVED,
    ) {}

    public static function fromWorkingCopy(WorkingCopy $copy): self
    {
        $bytes = $copy->bytes;
        if ($bytes === null) {
            return new self(null, ['blocks' => []], ['blocks' => []], [], [], [], $copy->baseVersion);
        }

        $read = Agent::read($bytes);
        $unrepresented = is_array($read['unrepresented'] ?? null) ? array_values(array_filter($read['unrepresented'], is_array(...))) : [];
        unset($read['unrepresented'], $read['sourceKind']);

        $registered = PictureRegistry::register($read);

        return new self($bytes, $read, $registered->named, $registered->images, $registered->pictures, $unrepresented, $copy->baseVersion);
    }

    /** The store's working copy under the default policy (WorkingCopy::fromStore()), read. */
    public static function fromStore(DocumentStore $store): self
    {
        return self::fromWorkingCopy(WorkingCopy::fromStore($store));
    }

    public function exists(): bool
    {
        return $this->bytes !== null;
    }
}
