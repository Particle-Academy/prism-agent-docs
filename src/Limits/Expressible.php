<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

use Prism\AgentDocs\Support\DocumentTree;
use Prism\AgentDocs\Support\Paths;

/**
 * A part of a limited request that a .docx CAN do.
 *
 * "Use no margins for the header" is partly inexpressible (the header shares
 * the body's side margins) and partly not: the header DISTANCE, how far the
 * header sits from the top edge, is one value a .docx holds. Reporting the
 * limit is no reason to skip that part (G-316 lab run 01a2002d: a live model
 * reported the limit and drafted nothing). So while the limit is reported, a
 * request that asks for this part, by one of `askedBy`, in a document with one
 * of `bands`, must get a draft holding `value` at `path`.
 *
 * `path` is never one of a limit's `alternativePaths`: the catalogue refuses
 * that, so doing the doable part can never apply an offered alternative.
 *
 * Where it does not show, it is not done: in a document with none of `bands`
 * the path is set back to the working copy's value, like an alternative's
 * (G-316 live run 01a20049, S3: a header distance of zero on a letterhead
 * with no header, handed over as "the draft is ready"). Where it is done,
 * the person is told so in `done`, between "The draft is ready." and the
 * limit's offer, so the message still matches the draft.
 */
final readonly class Expressible
{
    /**
     * @param  list<string>  $askedBy  the phrases that ask for this part (see Support\Asked)
     * @param  list<string>  $bands  the bands it positions; with none of them in the document it does nothing visible, so it is neither required nor kept
     * @param  list<array{package: string, file: string, section: string, quote: string}>  $sources
     * @param  string  $done  the one plain sentence the person is told when the draft makes this change
     */
    public function __construct(
        public string $path,
        public int|float $value,
        public string $instruction,
        public array $askedBy,
        public array $bands,
        public array $sources,
        public string $done,
    ) {}

    /**
     * Whether the document holds this part's value. Numbers compare by value;
     * absent is the writer's default, never this value.
     *
     * @param  array<mixed>  $document
     */
    public function heldBy(array $document): bool
    {
        [$has, $value] = Paths::get($document, $this->path);

        return $has && (is_int($value) || is_float($value)) && (float) $value === (float) $this->value;
    }

    /**
     * Whether the document has a band this part positions, with something in it.
     *
     * @param  array<mixed>  $document
     */
    public function applies(array $document): bool
    {
        $page = is_array($document['page'] ?? null) ? $document['page'] : [];

        return array_any($this->bands, static fn (string $band): bool => is_array($page[$band] ?? null) && DocumentTree::hasContent($page[$band]));
    }
}
