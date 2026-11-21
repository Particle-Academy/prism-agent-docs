<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Tools\DocsTools;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| G-316 scenario 1 — revise a letterhead whose pictures sit in the header
|--------------------------------------------------------------------------
|
| Moic's failure: the host named only the BODY's pictures to the writer, so
| `src: "file-1"` failed with image_unknown thirteen times and an empty
| document was saved. Pass: the header holds both pictures (same bytes), the
| body is empty and the footer is kept. Fail: any image_unknown, or a draft
| with no text and no picture.
|
*/

const SCENARIO_1_ASK = 'Keep the logo at the left of the header with the divider full-width beneath it, set the header distance to zero, keep the body empty, keep the page number.';

function scenarioOneDraft(): array
{
    // The model's revision, as a model writes it: pictures by the names it
    // was given, the logo left and the divider at the full text width.
    return [ScriptedModel::call(DocsTools::WRITE_DRAFT, [
        'document' => [
            'blocks' => [],
            'page' => [
                'margins' => ['header' => 0],
                'header' => ['blocks' => [
                    ['type' => 'image', 'src' => 'image:picture-1', 'widthPx' => 96, 'heightPx' => 48],
                    ['type' => 'image', 'src' => 'image:picture-2', 'widthPx' => 624, 'heightPx' => 5],
                ]],
            ],
        ],
    ])];
}

it('names the header pictures to the writer, so the header revision lands with the same bytes', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([scenarioOneDraft(), 'Done.']);

    $result = editDocument($store, SCENARIO_1_ASK);

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($model->resultsOf(DocsTools::WRITE_DRAFT))->toHaveCount(1)
        ->and($model->resultsOf(DocsTools::WRITE_DRAFT)[0]['ok'])->toBeTrue()
        ->and(json_encode($model->calls()))->not->toContain('image_unknown');

    $draft = $store->draft();
    expect($draft)->not->toBeNull();
    $read = Agent::read((string) $draft);

    // The header holds both pictures, byte for byte, logo first.
    expect(Letterheads::picturesByPart((string) $draft))->toBe([
        '/page/header' => [hash('sha256', Letterheads::logo()), hash('sha256', Letterheads::divider())],
    ])
        // The body is empty, the footer's page number is kept, the header distance is zero.
        ->and($read['blocks'])->toBe([])
        ->and($read['page']['footer'])->toBe(['center' => '{page}'])
        ->and($read['page']['margins']['header'] ?? null)->toEqual(0);
});

it('tells the model every picture in the saved file and where it is', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install(['Done.']);

    editDocument($store, SCENARIO_1_ASK);

    expect(shownToModel($model))
        ->toContain('image:picture-1 in /page/header')
        ->toContain('image:picture-2 in /page/header')
        ->not->toContain('base64');
});

it('refuses a draft with no text and no picture, and saves nothing', function (): void {
    // The saved result of Moic's run: everything gone. The self-check reads
    // the written file and refuses it; nothing reaches the store.
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['blocks' => []], 'remove' => ['header', 'footer']])],
        'I saved the document.',
    ]);

    $result = editDocument($store, SCENARIO_1_ASK);

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->draft)->toBeNull()
        ->and($store->draft())->toBeNull()
        ->and($model->resultsOf(DocsTools::WRITE_DRAFT)[0]['problems'][0]['code'])->toBe('empty_document')
        ->and($result->message)->toBe('The draft could not be made: it came out empty.');
});

it('accepts an empty document when the HOST says empty was asked for', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['blocks' => []], 'remove' => ['header', 'footer']])],
        'Done.',
    ]);

    $result = editDocument($store, 'Clear everything, I want a blank page.', new DocsOptions(allowEmpty: true));

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->message)->toBe(DocsResult::READY)
        ->and(Agent::read((string) $store->draft()))->toBe(['blocks' => []]);
});

it('does not hand over an earlier draft when the last write was refused', function (): void {
    // The run ended on a draft that failed its checks. Saving the earlier one
    // instead would be saving something other than what the run ended on.
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    ScriptedModel::install([
        scenarioOneDraft(),
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:file-1']]]]]])],
        'Done.',
    ]);

    $result = editDocument($store, SCENARIO_1_ASK);

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->message)->toBe('The draft could not be made: a picture it needed could not be placed.')
        ->and($store->draft())->toBeNull();
});

it('lets the model fix a refused draft in the same turn', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:file-1']]]]]])],
        scenarioOneDraft(),
        'Done.',
    ]);

    $result = editDocument($store, SCENARIO_1_ASK);

    $writes = $model->resultsOf(DocsTools::WRITE_DRAFT);
    expect($writes[0]['ok'])->toBeFalse()
        ->and($writes[0]['problems'][0]['code'])->toBe('image_unknown')
        ->and($writes[1]['ok'])->toBeTrue()
        ->and($result->outcome)->toBe(Outcome::Ready);
});
