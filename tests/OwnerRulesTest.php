<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Tools\DocsTools;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| The two rules Moic's owner set, which the lab must hold
|--------------------------------------------------------------------------
|
| 1. A whole rewrite that says nothing of the header KEEPS the header.
| 2. No narration of what was built: say the draft is ready, and say more
|    only when something asked for could not be done.
|
*/

function rewriteBody(): array
{
    return [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['blocks' => [
        ['type' => 'paragraph', 'runs' => [['text' => 'Dear Ms Example,']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'We are pleased to confirm the appointment from 1 December.']]],
        ['type' => 'paragraph', 'runs' => [['text' => 'Yours sincerely,']]],
    ]]])];
}

it('keeps the header and the footer through a whole rewrite that says nothing of them', function (): void {
    $saved = Letterheads::headerLetterheadWithBody();
    $store = new InMemoryDocumentStore($saved);
    ScriptedModel::install([rewriteBody(), 'Done.']);

    $result = editDocument($store, 'Rewrite this letter to confirm the appointment from 1 December.');

    expect($result->outcome)->toBe(Outcome::Ready);
    $draft = Agent::read((string) $store->draft());
    $before = Agent::read($saved);

    expect($draft['blocks'][1]['runs'][0]['text'])->toContain('1 December')
        ->and($draft['page']['header'] ?? null)->not->toBeNull('the header was lost')
        ->and(Agent::equivalent(['blocks' => [], 'page' => ['header' => $before['page']['header']]], ['blocks' => [], 'page' => ['header' => $draft['page']['header'] ?? []]]))->toBeTrue()
        ->and(Letterheads::picturesByPart((string) $store->draft())['/page/header'] ?? [])->toBe([hash('sha256', Letterheads::logo()), hash('sha256', Letterheads::divider())])
        ->and($draft['page']['footer'] ?? null)->toBe(['center' => '{page}']);
});

it('lets a draft change the header when the draft writes the header', function (): void {
    // The other side of the rule: keeping what the draft does not mention must
    // not freeze what it does. (SelfCheckTest covers a kept header that comes
    // out different, which is refused.)
    $store = new InMemoryDocumentStore(Letterheads::headerLetterheadWithBody());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:picture-1']]]]]])],
        'Done.',
    ]);

    $result = editDocument($store, 'Make the logo bigger.');

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($model->resultsOf(DocsTools::WRITE_DRAFT)[0]['ok'])->toBeTrue();
});

it('says only that the draft is ready, whatever the model narrates', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterheadWithBody());
    ScriptedModel::install([
        rewriteBody(),
        "I've rewritten your letter! Here's what I did:\n- Kept your logo and divider in the header\n- Wrote three new paragraphs\n- Preserved the page number in the footer",
    ]);

    $result = editDocument($store, 'Rewrite this letter to confirm the appointment from 1 December.');

    expect($result->message)->toBe(DocsResult::READY)
        ->and($result->message)->toBe('The draft is ready.');
});

it('says nothing about pictures, headers or steps when a limit is reported', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    ScriptedModel::install([
        [ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared'])],
        'I looked at the table, the divider and the margins, and I found that...',
    ]);

    $result = editDocument($store, 'Use no margins for the header.');

    expect($result->message)->not->toContain('table')
        ->and($result->message)->not->toContain('I ');
});
