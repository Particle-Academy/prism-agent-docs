<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

/**
 * A DocumentStore held in memory: for tests, for a single request, or as the
 * shape a host's own store copies.
 */
final class InMemoryDocumentStore implements DocumentStore
{
    public function __construct(private readonly ?string $saved = null, private ?string $draft = null) {}

    public function saved(): ?string
    {
        return $this->saved;
    }

    public function draft(): ?string
    {
        return $this->draft;
    }

    public function putDraft(string $bytes): void
    {
        $this->draft = $bytes;
    }
}
