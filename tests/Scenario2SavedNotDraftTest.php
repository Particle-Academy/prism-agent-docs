<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\DocsOptions;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Tools\DocsTools;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| G-316 scenario 2 — an edit must not start from an empty draft
|--------------------------------------------------------------------------
|
| State: the saved file is scenario 1's, and a pending draft exists that holds
| nothing. Ask: any revision of the header. Pass: the writer is shown the
| SAVED file's content, and the new draft replaces the empty one.
|
| A draft that DOES hold something is continued instead (WorkingCopyTest):
| the rule is that an empty draft is never a starting point, not that a draft
| never is.
|
*/

function swapHeaderPictures(): array
{
    return [ScriptedModel::call(DocsTools::WRITE_DRAFT, [
        'document' => ['page' => ['header' => ['blocks' => [
            ['type' => 'image', 'src' => 'image:picture-2'],
            ['type' => 'image', 'src' => 'image:picture-1', 'widthPx' => 96, 'heightPx' => 48],
        ]]]],
    ])];
}

it('shows the writer the saved file, not the empty pending draft', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::emptyDraft());
    $model = ScriptedModel::install([swapHeaderPictures(), 'Done.']);

    editDocument($store, 'Put the divider above the logo in the header.');

    // What the model saw is the saved letterhead: its header and its pictures.
    expect(shownToModel($model))
        ->toContain('THE DOCUMENT TO REVISE')
        ->toContain('"header":{"blocks":[{"type":"image","src":"image:picture-1"')
        ->toContain('image:picture-1 in /page/header')
        ->toContain('"footer":{"center":"{page}"}');
});

it('replaces the empty draft with the new one', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::emptyDraft());
    ScriptedModel::install([swapHeaderPictures(), 'Done.']);

    $result = editDocument($store, 'Put the divider above the logo in the header.');

    expect($result->outcome)->toBe(Outcome::Ready);
    $draft = (string) $store->draft();
    expect($draft)->not->toBe(Letterheads::emptyDraft())
        ->and(Letterheads::picturesByPart($draft))->toBe([
            '/page/header' => [hash('sha256', Letterheads::divider()), hash('sha256', Letterheads::logo())],
        ])
        ->and(Agent::read($draft)['page']['footer'])->toBe(['center' => '{page}']);
});

it('starts from the saved file when the draft holds only blank bands', function (): void {
    // "Holds something" means text or a picture, not "has a header element".
    $hollow = Agent::toBytes(['blocks' => [], 'page' => ['header' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '   ']]]]]]]);
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), $hollow);
    $model = ScriptedModel::install([swapHeaderPictures(), 'Done.']);

    $result = editDocument($store, 'Put the divider above the logo in the header.');

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and(shownToModel($model))->toContain('image:picture-1 in /page/header');
});

it('creates a document when nothing is saved, with a picture the host offers', function (): void {
    $store = new InMemoryDocumentStore(null, Letterheads::emptyDraft());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => [
            'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Minutes of the meeting']]]],
            'page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:logo']]]],
        ]])],
        'Done.',
    ]);

    $result = editDocument($store, 'Start a minutes document with our logo in the header.', new DocsOptions(images: ['logo' => Letterheads::logo()]));

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and(shownToModel($model))->toContain('THERE IS NO DOCUMENT YET')->toContain('- image:logo')
        ->and(Letterheads::picturesByPart((string) $store->draft()))->toBe(['/page/header' => [hash('sha256', Letterheads::logo())]]);
});
