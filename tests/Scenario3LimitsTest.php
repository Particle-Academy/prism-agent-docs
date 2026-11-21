<?php

declare(strict_types=1);

use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Tools\DocsTools;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| G-316 scenario 3 — asked for a layout the format can't express, say so
|--------------------------------------------------------------------------
|
| File: a body-designed letterhead with one-inch margins and no page header.
| Ask: "Use no margins for the header" (header art edge to edge, body keeps
| its margins). DOCX side margins are shared by header, body and footer, and
| last-word blocks cannot bleed past the margin. Pass: within one short turn,
| a plain statement of the limit plus the closest alternative. No no-op draft
| presented as the change.
|
*/

const SCENARIO_3_ASK = 'Use no margins for the header.';

it('states the limit and the closest alternative, and drafts nothing', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared', 'alternative' => 0])],
        'Unfortunately Word does not allow that, but here is what I can do instead, at some length...',
    ]);

    $result = editDocument($store, SCENARIO_3_ASK);
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    expect($result->outcome)->toBe(Outcome::Limit)
        ->and($result->draft)->toBeNull()
        ->and($store->draft())->toBeNull()
        ->and($result->message)->toBe($limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]))
        // One short turn: one model request, two sentences, no model prose.
        ->and($model->requests())->toHaveCount(1)
        ->and(preg_match_all('/[.!?](\s|$)/', $result->message))->toBe(2)
        ->and($result->message)->not->toContain('Unfortunately');
});

it('refuses a draft that changes nothing, so it cannot be presented as the change', function (): void {
    // Moic's failure: the writer produced a draft identical to the file and
    // the host showed it as the revision.
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['margins' => ['left' => 72, 'right' => 72]]]])],
        'Here is your document with no header margins!',
    ]);

    $result = editDocument($store, SCENARIO_3_ASK);

    $write = $model->resultsOf(DocsTools::WRITE_DRAFT)[0];
    expect($write['ok'])->toBeFalse()
        ->and($write['problems'][0]['code'] ?? null)->toBe('no_change')
        ->and($result->outcome)->not->toBe(Outcome::Ready)
        ->and($result->message)->not->toBe(DocsResult::READY)
        ->and($store->draft())->toBeNull();
});

it('reports the limit after a no-op draft is refused, in the same turn', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => []])],
        [ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared'])],
        'Done.',
    ]);

    $result = editDocument($store, SCENARIO_3_ASK);

    expect($result->outcome)->toBe(Outcome::Limit)
        ->and($model->requests())->toHaveCount(1)
        ->and($store->draft())->toBeNull();
});

it('accepts only a catalogued limit, and the person hears the catalogue\'s words', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'word-is-too-old'])],
        'Done.',
    ]);

    $result = editDocument($store, SCENARIO_3_ASK);

    expect($model->resultsOf(DocsTools::REPORT_LIMIT)[0]['ok'])->toBeFalse()
        ->and($result->outcome)->toBe(Outcome::NoChange)
        ->and($result->limits)->toBe([]);
});

it('says the draft is ready and names the limit when part of a request can be done', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    ScriptedModel::install([
        [
            ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared', 'alternative' => 1]),
            ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'Proposal for Example Ltd']]),
        ],
        'Done.',
    ]);

    $result = editDocument($store, 'Use no margins for the header, and title the document "Proposal for Example Ltd".');
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    expect($result->outcome)->toBe(Outcome::Ready)
        // The model chose alternative 1, "as it is now": never offered (G-316 live run 01a2007d).
        ->and($result->message)->toBe(DocsResult::READY.' '.$limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]));
});

it('gives the model every catalogued limit, with its alternatives', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install(['Done.']);

    editDocument($store, SCENARIO_3_ASK);

    $shown = shownToModel($model);
    foreach (docsAgent()->catalog()->all() as $limit) {
        expect($shown)->toContain('`'.$limit->id.'` (')->toContain($limit->statement)
            ->toContain($limit->alternatives[0]);
    }
});
