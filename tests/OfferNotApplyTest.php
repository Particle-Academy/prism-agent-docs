<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Check\SelfCheck;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Source;
use Prism\AgentDocs\Support\Asked;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| Moic's live test of 0.1.0: an alternative is OFFERED, never applied
|--------------------------------------------------------------------------
|
| Same request, three runs, a real letterhead and a live model. All three
| reported `side-margins-shared` and told the person "the closest
| alternative: set the side margins to zero for the whole page". Two of the
| drafts already had zero side margins; one did not. So the same request gave
| two different documents, and for two of them the message offered what had
| already been done.
|
| The rule is enforced in code: while a limit is reported, every path its
| alternatives would change keeps the working copy's value, unless the
| request asked for exactly that change. Whatever the model does, the draft
| is the same, and the message matches it.
|
*/

const MOIC_ASK = 'Use this file as a letterhead template with no margins for the header.';

const MOIC_ASK_AND_TITLE = 'Use this file as a letterhead template with no margins for the header, and title it "Proposal for Example Ltd".';

const ASKED_FOR_ZERO = 'Use no margins for the header; if that cannot be done, set the side margins to zero for the whole page.';

function zeroSideMargins(array $document = []): ToolCall
{
    return ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => array_replace_recursive($document, ['page' => ['margins' => ['left' => 0, 'right' => 0]]])]);
}

function reportSideMargins(): ToolCall
{
    return ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared', 'alternative' => 0]);
}

/** @return array{0: mixed, 1: mixed} the side margins of a written file; absent is last-word's default */
function sideMargins(string $docx): array
{
    $page = Agent::read($docx)['page'] ?? [];

    return [$page['margins']['left'] ?? 'default', $page['margins']['right'] ?? 'default'];
}

dataset('limit only', [
    'the model applies the alternative, then reports' => [[[zeroSideMargins(), reportSideMargins()], 'Done.']],
    'the model reports, then applies the alternative' => [[[reportSideMargins()], [zeroSideMargins()], 'Done.']],
    'the model does not apply it' => [[[reportSideMargins()], 'Done.']],
]);

it('hands back the same outcome and message whether or not the model applied the alternative', function (array $script): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    ScriptedModel::install($script);
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    $result = editDocument($store, MOIC_ASK);

    expect($result->outcome)->toBe(Outcome::Limit)
        ->and($result->draft)->toBeNull()
        ->and($store->draft())->toBeNull()
        ->and($result->message)->toBe($limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]))
        // An offer reads as an offer: the person is told it was not applied.
        ->and($result->message)->toContain('not applied unless you ask for it')
        ->and($result->message)->not->toContain((string) $limit->applied);
})->with('limit only');

it('writes the same draft, byte for byte, whether or not the model applied the alternative', function (): void {
    $title = ['title' => 'Proposal for Example Ltd'];
    $scripts = [
        'applied' => [[reportSideMargins(), zeroSideMargins($title)], 'Done.'],
        'applied before the report' => [[zeroSideMargins($title)], [reportSideMargins()], 'Done.'],
        'not applied' => [[reportSideMargins(), ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => $title])], 'Done.'],
    ];
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    $drafts = [];
    foreach ($scripts as $name => $script) {
        $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
        ScriptedModel::install($script);
        $result = editDocument($store, MOIC_ASK_AND_TITLE);

        expect($result->outcome)->toBe(Outcome::Ready, $name)
            ->and($result->message)->toBe(DocsResult::READY.' '.$limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]), $name)
            ->and(sideMargins((string) $result->draft))->toBe(sideMargins(Letterheads::bodyLetterhead()), $name)
            ->and(Agent::read((string) $result->draft)['title'] ?? null)->toBe('Proposal for Example Ltd', $name);
        $drafts[$name] = $result->draft;
    }

    expect($drafts['applied'])->toBe($drafts['not applied'])
        ->and($drafts['applied before the report'])->toBe($drafts['not applied']);
});

it('tells the model the alternative was taken out', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([[reportSideMargins(), zeroSideMargins(['title' => 'Proposal'])], 'Done.']);

    editDocument($store, MOIC_ASK);

    $write = $model->resultsOf(DocsTools::WRITE_DRAFT)[0];
    expect($write['ok'])->toBeTrue()
        ->and(implode(' ', $write['changed'] ?? []))->toContain('page.margins.left')
        ->toContain('offered to the person, not applied');
});

it('applies the alternative when the request asks for exactly that change, and says it was done', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    ScriptedModel::install([[reportSideMargins(), zeroSideMargins()], 'Done.']);
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    $result = editDocument($store, ASKED_FOR_ZERO);

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and(sideMargins((string) $result->draft))->toBe([0, 0])
        ->and($result->message)->toBe(DocsResult::READY.' '.$limit->statement.' '.$limit->applied)
        ->and($result->message)->not->toContain(trim(DocsResult::OFFER));
});

it('reads a request as asking for the change only when it says so, narrowly', function (string $request, bool $asked): void {
    $phrases = docsAgent()->catalog()->get('side-margins-shared')->askedBy;

    expect(Asked::forAny($request, $phrases))->toBe($asked);
})->with([
    'Moic\'s request' => [MOIC_ASK, false],
    'no margins for the header' => ['Use no margins for the header.', false],
    'edge to edge header art' => ['Make the header art run edge to edge.', false],
    'zero side margins for the header' => ['Set the side margins to zero for the header.', false],
    'explicit, whole page' => ['Set the side margins to zero for the whole page.', true],
    'explicit, with a digit' => ['set the side margins to 0 for the whole page', true],
    'explicit, odd spacing and case' => ["Set the SIDE margins  to\nzero for the whole document.", true],
    'negated' => ["Don't set the side margins to zero for the whole page.", false],
    'negated, typographic apostrophe' => ["Please don\u{2019}t set the side margins to zero for the whole page.", false],
    'inside a longer word' => ['Set the outside margins to zero for the whole page.', false],
]);

it('refuses, in the self-check, a file that makes a reported limit\'s alternative', function (): void {
    $source = Source::fromStore(new InMemoryDocumentStore(Letterheads::bodyLetterhead()));
    $read = $source->read;
    $read['page'] = ['margins' => ['left' => 0, 'right' => 0]];
    $written = Agent::toBytes($read);

    $problems = SelfCheck::run($source, $source->named, $source->images, $written, [], false, ['page.margins.left', 'page.margins.right']);

    expect(array_map(fn (Problem $p): string => $p->code.' '.$p->where, $problems))
        ->toBe(['alternative_applied /page/margins/left', 'alternative_applied /page/margins/right']);
});

/*
|--------------------------------------------------------------------------
| Moic's live test of 0.1.0: no first-page header nobody asked for
|--------------------------------------------------------------------------
|
| The letterhead art moved into `page.firstHeader`, so page two had no logo.
| A first-page-only band the working copy did not have, in a request that did
| not ask for first-page-only, becomes the band every page shows.
|
*/

function logoInFirstHeader(array $page = []): ToolCall
{
    return ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => array_replace(
        ['firstHeader' => ['blocks' => [['type' => 'image', 'src' => 'image:picture-1', 'widthPx' => 96, 'heightPx' => 48]]]],
        $page,
    )]]);
}

it('writes an introduced first-page header as the header every page shows', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([[logoInFirstHeader()], 'Done.']);

    $result = editDocument($store, 'Use this file as a letterhead template: put the logo in the header.');

    expect($result->outcome)->toBe(Outcome::Ready);
    $page = Agent::read((string) $result->draft)['page'] ?? [];
    expect($page)->not->toHaveKey('firstHeader')
        ->and(Letterheads::picturesByPart((string) $result->draft)['/page/header'] ?? [])->toBe([hash('sha256', Letterheads::logo())])
        ->and($result->message)->toBe(DocsResult::READY)
        ->and(implode(' ', $model->resultsOf(DocsTools::WRITE_DRAFT)[0]['changed'] ?? []))->toContain('page.firstHeader was written as page.header');
});

it('refuses an introduced first-page header written beside a header, so the art is not lost from page two', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([[logoInFirstHeader(['header' => ['right' => 'Example Ltd']])], 'Done.']);

    $result = editDocument($store, 'Put the logo in the header.');

    expect($model->resultsOf(DocsTools::WRITE_DRAFT)[0]['problems'][0]['code'] ?? null)->toBe(Problem::FIRST_PAGE_BAND)
        ->and($result->outcome)->toBe(Outcome::Failed)
        ->and($result->draft)->toBeNull();
});

it('keeps a first-page header the working copy already has, and says it shows on the first page only', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::firstPageLetterhead());
    ScriptedModel::install([
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Dear Mr Example,']]]]]])],
        'Done.',
    ]);

    $result = editDocument($store, 'Address the letter to Mr Example.');

    $parts = Letterheads::picturesByPart((string) $result->draft);
    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($parts['/page/firstHeader'] ?? [])->toBe([hash('sha256', Letterheads::logo()), hash('sha256', Letterheads::divider())])
        ->and($result->message)->toBe(DocsResult::READY.' '.DocsResult::FIRST_PAGE_ONLY['firstHeader']);
});

it('keeps a first-page header the working copy already has when the draft rewrites it', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::firstPageLetterhead());
    ScriptedModel::install([[logoInFirstHeader()], 'Done.']);

    $result = editDocument($store, 'Drop the divider from the letterhead.');

    $parts = Letterheads::picturesByPart((string) $result->draft);
    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($parts['/page/firstHeader'] ?? [])->toBe([hash('sha256', Letterheads::logo())])
        ->and(Agent::read((string) $result->draft)['page']['header'] ?? null)->toBe(['right' => 'Example Ltd'])
        ->and($result->message)->toBe(DocsResult::READY.' '.DocsResult::FIRST_PAGE_ONLY['firstHeader']);
});

it('keeps a first-page header the request asks for, and says it shows on the first page only', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    ScriptedModel::install([[logoInFirstHeader()], 'Done.']);

    $result = editDocument($store, 'Put the logo in the header on the first page only.');

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and(Letterheads::picturesByPart((string) $result->draft)['/page/firstHeader'] ?? [])->toBe([hash('sha256', Letterheads::logo())])
        ->and($result->message)->toBe(DocsResult::READY.' '.DocsResult::FIRST_PAGE_ONLY['firstHeader']);
});

it('refuses, in the self-check, a file with a first-page band the working copy lacked', function (): void {
    $source = Source::fromStore(new InMemoryDocumentStore(Letterheads::bodyLetterhead()));
    $read = $source->read;
    $read['page'] = ['firstHeader' => ['left' => 'Example Ltd']];
    $written = Agent::toBytes($read);

    $problems = SelfCheck::run($source, $source->named, $source->images, $written, [], false, [], ['firstHeader', 'firstFooter']);

    expect(array_map(fn (Problem $p): string => $p->code.' '.$p->where, $problems))->toBe(['first_page_band /page/firstHeader']);
});
