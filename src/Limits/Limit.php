<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

/** One thing a last-word .docx cannot express, with what to offer instead and where last-word says so. */
final readonly class Limit
{
    /**
     * @param  list<string>  $alternatives  closest first
     * @param  list<array{package: string, file: string, section: string, quote: string}>  $sources
     * @param  list<string>  $alternativePaths  the document paths its alternatives would change (`page.margins.left`): OFFERED, never applied, so a draft's change to them is reverted while this limit is reported
     * @param  list<string>  $askedBy  the phrases that make a request ask for that change itself, so it is applied (see Support\Asked)
     * @param  ?string  $applied  what the person is told when the request asked for the change and the draft makes it
     */
    public function __construct(
        public string $id,
        public string $statement,
        public string $appliesWhen,
        public array $alternatives,
        public array $sources,
        public ?string $council = null,
        public array $alternativePaths = [],
        public array $askedBy = [],
        public ?string $applied = null,
    ) {}
}
