<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Check\SelfCheck;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Source;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\AgentDocs\WorkingCopy;
use Tests\Fixtures\Participant;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| The host chooses the working copy (Moic's design, G-316)
|--------------------------------------------------------------------------
|
| The agent never picks between the saved file and a draft. The host hands it
| a WorkingCopy; a DocumentStore's default is the pending draft when it holds
| something, otherwise the saved file. The working copy's baseVersion comes
| back on the result, so the host can refuse a save once the file has moved.
|
*/

function biggerLogo(): array
{
    return [ScriptedModel::call(DocsTools::WRITE_DRAFT, [
        'document' => ['page' => ['header' => ['blocks' => [
            ['type' => 'image', 'src' => 'image:picture-1', 'widthPx' => 192, 'heightPx' => 96],
            ['type' => 'image', 'src' => 'image:picture-2'],
        ]]]],
    ])];
}

it('continues a non-empty pending draft: the model is shown the draft', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::continuedDraft());
    $model = ScriptedModel::install([biggerLogo(), 'Done.']);

    editDocument($store, 'Now make the logo bigger.');

    expect(shownToModel($model))
        ->toContain('THE DOCUMENT TO REVISE')
        ->toContain('The draft the person is reading.')
        ->toContain('"footer":{"center":"Confidential"}');
});

it('builds the edit on the continued draft, keeping what the draft leaves out from IT', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::continuedDraft());
    ScriptedModel::install([biggerLogo(), 'Done.']);

    $result = editDocument($store, 'Now make the logo bigger.');

    expect($result->outcome)->toBe(Outcome::Ready);
    $draft = Agent::read((string) $store->draft());
    // The body and footer are the DRAFT's, not the saved file's empty body
    // and "{page}" footer: the model sent only the header.
    expect($draft['blocks'][1]['runs'][0]['text'])->toBe('The draft the person is reading.')
        ->and($draft['page']['footer'])->toBe(['center' => 'Confidential'])
        ->and($draft['page']['header']['blocks'][0]['widthPx'])->toBe(192);
});

it('counts a draft that holds only a footer line as something to continue', function (): void {
    $copy = WorkingCopy::fromStore(new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::footerOnlyDraft()));

    expect($copy->bytes)->toBe(Letterheads::footerOnlyDraft());
});

it('counts a draft that holds only a header picture as something to continue', function (): void {
    $copy = WorkingCopy::fromStore(new InMemoryDocumentStore(Letterheads::bodyLetterhead(), Letterheads::headerLetterhead()));

    expect($copy->bytes)->toBe(Letterheads::headerLetterhead());
});

it('falls back to the saved file when the draft is empty or absent', function (?string $draft): void {
    $copy = WorkingCopy::fromStore(new InMemoryDocumentStore(Letterheads::headerLetterhead(), $draft));

    expect($copy->bytes)->toBe(Letterheads::headerLetterhead());
})->with([
    'an empty draft' => [Letterheads::emptyDraft()],
    'no draft' => [null],
    'a zero-byte draft' => [''],
]);

it('uses an explicit host working copy verbatim, and writes nothing anywhere', function (): void {
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'Proposal, revised']])],
        'Done.',
    ]);

    $result = editDocument(new WorkingCopy(Letterheads::bodyLetterhead(), 'rev-7'), 'Retitle it.');

    expect(shownToModel($model))->toContain('Prepared for: Example Ltd')
        ->and($result->outcome)->toBe(Outcome::Ready)
        ->and(Agent::read((string) $result->draft)['blocks'][0]['type'])->toBe('table');
});

it('treats an empty working copy as a create', function (): void {
    expect(new WorkingCopy('', 'v1')->exists())->toBeFalse()
        ->and(new WorkingCopy(null, 'v1')->exists())->toBeFalse();
});

it('refuses a working copy with no base version', function (): void {
    new WorkingCopy(Letterheads::bodyLetterhead(), '');
})->throws(InvalidArgumentException::class);

it('round-trips an explicit base version on every outcome', function (array $script, Outcome $outcome): void {
    ScriptedModel::install($script);

    $result = editDocument(new WorkingCopy(Letterheads::bodyLetterhead(), 'host-rev:42'), 'Anything.');

    expect($result->outcome)->toBe($outcome)
        ->and($result->baseVersion)->toBe('host-rev:42');
})->with([
    'ready' => [[[ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'New']])], 'Done.'], Outcome::Ready],
    'limit' => [[[ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared'])], 'Done.'], Outcome::Limit],
    'no change' => [['Done.'], Outcome::NoChange],
]);

it('round-trips the base version when the edit fails before the model', function (): void {
    $result = editDocument(new WorkingCopy("PK\x03\x04 not a docx", 'host-rev:43'), 'Anything.');

    expect($result->outcome)->toBe(Outcome::Failed)
        ->and($result->baseVersion)->toBe('host-rev:43');
});

it('gives a store\'s working copy the SAVED file\'s version, even when it continues the draft', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead(), Letterheads::continuedDraft());
    ScriptedModel::install([biggerLogo(), 'Done.']);

    $result = editDocument($store, 'Now make the logo bigger.');

    expect($result->baseVersion)
        ->toBe(WorkingCopy::versionOf(Letterheads::headerLetterhead()))
        ->toBe('sha256:'.hash('sha256', Letterheads::headerLetterhead()))
        ->and(WorkingCopy::versionOf(null))->toBe(WorkingCopy::NOTHING_SAVED);
});

it('self-checks an unmentioned band against the WORKING COPY\'s band, not the saved file\'s', function (): void {
    $source = Source::fromWorkingCopy(new WorkingCopy(Letterheads::continuedDraft(), 'v1'));

    // A file with the saved file's footer ("{page}") where the working copy
    // has "Confidential": the band the draft did not mention was changed.
    $problems = SelfCheck::run($source, ['blocks' => []], $source->images, Letterheads::headerLetterheadWithBody(), ['footer'], false);

    expect(array_map(static fn (Problem $p): string => $p->code.' '.$p->where, $problems))->toContain(Problem::BAND_CHANGED.' /page/footer');
});

it('continues the first tool call\'s draft on the second call', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Written by the first call.']]]]]])],
        'Done.',
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'Second']])],
        'Done.',
    ]);
    $tool = docsAgent()->tool(Participant::create(['name' => 'Owner']), $store);

    $tool->handle(request: 'Write the letter.');
    $tool->handle(request: 'Title it.');

    $requests = $model->requests();
    $second = end($requests);
    expect((string) $second->prompt().implode("\n", array_map(static fn ($m): string => property_exists($m, 'content') && is_string($m->content) ? $m->content : '', $second->messages())))
        ->toContain('Written by the first call.')
        ->and(Agent::read((string) $store->draft())['blocks'][0]['runs'][0]['text'])->toBe('Written by the first call.');
});
