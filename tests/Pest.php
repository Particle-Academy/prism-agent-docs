<?php

declare(strict_types=1);

use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\DocumentStore;
use Prism\AgentDocs\WorkingCopy;
use Tests\Fixtures\Participant;
use Tests\Support\ScriptedModel;
use Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function docsAgent(): DocsAgent
{
    return app(DocsAgent::class);
}

/** Everything the model was shown in its first request: the system prompts, the prompt and every message, as text. */
function shownToModel(ScriptedModel $model): string
{
    $request = $model->requests()[0];
    $parts = [(string) $request->prompt()];
    foreach ($request->systemPrompts() as $system) {
        $parts[] = $system->content;
    }
    foreach ($request->messages() as $message) {
        $parts[] = property_exists($message, 'content') && is_string($message->content) ? $message->content : '';
    }

    return implode("\n", $parts);
}

function editDocument(WorkingCopy|DocumentStore $document, string $request, DocsOptions $options = new DocsOptions): DocsResult
{
    return docsAgent()->edit(Participant::create(['name' => 'Owner']), $document, $request, $options);
}
