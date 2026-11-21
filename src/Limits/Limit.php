<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Limits;

/** One thing a last-word .docx cannot express, with what to offer instead and where last-word says so. */
final readonly class Limit
{
    /**
     * @param  list<string>  $alternatives  closest first
     * @param  list<array{package: string, file: string, section: string, quote: string}>  $sources
     */
    public function __construct(
        public string $id,
        public string $statement,
        public string $appliesWhen,
        public array $alternatives,
        public array $sources,
        public ?string $council = null,
    ) {}
}
