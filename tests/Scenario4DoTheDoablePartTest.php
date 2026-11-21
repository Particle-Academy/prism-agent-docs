<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Check\SelfCheck;
use Prism\AgentDocs\DocsAgent;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Source;
use Prism\AgentDocs\Support\Asked;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| G-316 lab scenario S4: do what can be done, offer the rest
|--------------------------------------------------------------------------
|
| File: the header letterhead (logo and divider in the page header, a page
| number in the footer). Ask: "Use no margins for the header". Half of that is
| inexpressible (a Word header shares the body's side margins) and half is not
| (the header DISTANCE, how far the header sits from the top edge, is one
| value a .docx holds). Pass: a draft with page.margins.header = 0, the side
| margins untouched, and the message "The draft is ready." plus the
| side-margins-shared offer.
|
| Live lab run 01a2002d (claude-sonnet-5-5): limit reported, NO draft, and a
| message about the side margins only. Reporting a limit had ended the edit.
|
*/

const S4_ASK = 'Use no margins for the header.';

function headerToZero(array $margins = []): ToolCall
{
    return ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['margins' => array_replace(['header' => 0], $margins)]]]);
}

function reportSharedSideMargins(): ToolCall
{
    return ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared', 'alternative' => 0]);
}

/** @return array<string, mixed> the page margins of a written file; an absent margin is last-word's default */
function marginsOf(string $docx): array
{
    return Agent::read($docx)['page']['margins'] ?? [];
}

/** The last person-side message of a request: after the first, the pushback as the model received it. */
function lastUserMessage(ScriptedModel $model, int $request): string
{
    $text = '';
    foreach ($model->requests()[$request]->messages() as $message) {
        if ($message instanceof UserMessage) {
            $text = $message->content;
        }
    }

    return $text;
}

/** The message the lab expects: {"ready": true, "limits": ["side-margins-shared"]}. */
function readyWithSharedSideMargins(): string
{
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    return DocsResult::READY.' '.$limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]);
}

it('returns a run that reported the limit without drafting to the model, and hands back the doable part', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [reportSharedSideMargins()],
        'Word cannot do that.',
        [headerToZero()],
        'Done.',
    ]);

    $result = editDocument($store, S4_ASK);

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->limitIds())->toBe(['side-margins-shared'])
        ->and($result->message)->toBe(readyWithSharedSideMargins())
        ->and(marginsOf((string) $result->draft))->toBe(['header' => 0])
        ->and($store->draft())->toBe($result->draft)
        // The header pictures and the footer are kept.
        ->and(Letterheads::picturesByPart((string) $result->draft)['/page/header'] ?? [])->toBe([hash('sha256', Letterheads::logo()), hash('sha256', Letterheads::divider())])
        ->and(Agent::read((string) $result->draft)['page']['footer'] ?? null)->toBe(['center' => '{page}'])
        // One pushback, and it named the rule.
        ->and($model->requests())->toHaveCount(2)
        ->and($model->remaining())->toBe(0);

    // The pushback continues the same conversation: the model still has the
    // request and the document to revise.
    $people = array_map(fn ($m): string => $m instanceof UserMessage ? $m->content : '', $model->requests()[1]->messages());
    expect(implode("\n", $people))->toContain("REQUEST\n".S4_ASK);

    expect(lastUserMessage($model, 1))->toContain(Problem::EXPRESSIBLE_PART_SKIPPED)
        ->toContain('page.margins.header')
        ->toContain('stays offered, not applied');
});

it('passes a model that drafts the header distance and reports the limit, without a pushback', function (array $turn): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([$turn, 'Done.']);

    $result = editDocument($store, S4_ASK);

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->limitIds())->toBe(['side-margins-shared'])
        ->and($result->message)->toBe(readyWithSharedSideMargins())
        ->and(marginsOf((string) $result->draft))->toBe(['header' => 0])
        ->and($model->requests())->toHaveCount(1);
})->with([
    'report, then draft' => [[reportSharedSideMargins(), headerToZero()]],
    'draft, then report' => [[headerToZero(), reportSharedSideMargins()]],
]);

it('never changes the side margins, whatever the model drafts', function (array $script): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    ScriptedModel::install($script);

    $result = editDocument($store, S4_ASK);

    $margins = marginsOf((string) $result->draft);
    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->message)->toBe(readyWithSharedSideMargins())
        ->and($margins)->not->toHaveKey('left')
        ->and($margins)->not->toHaveKey('right')
        ->and($margins['header'] ?? null)->toBe(0);
})->with([
    'the doable part and the alternative together' => [[[reportSharedSideMargins(), headerToZero(['left' => 0, 'right' => 0])], 'Done.']],
    'the alternative first, then the doable part' => [[[headerToZero(['left' => 0, 'right' => 0]), reportSharedSideMargins()], 'Done.']],
    'the alternative instead of the doable part, pushed back' => [[
        [reportSharedSideMargins(), ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['margins' => ['left' => 0, 'right' => 0]]]])],
        'Done.',
        [headerToZero()],
        'Done.',
    ]],
]);

it('refuses, in write_draft, a draft that leaves out the doable part while the limit is reported', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [reportSharedSideMargins(), ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'Letterhead']])],
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['title' => 'Letterhead', 'page' => ['margins' => ['header' => 0]]]])],
        'Done.',
    ]);

    $result = editDocument($store, 'Use no margins for the header, and title it Letterhead.');

    $writes = $model->resultsOf(DocsTools::WRITE_DRAFT);
    expect($writes[0]['ok'])->toBeFalse()
        ->and(array_column($writes[0]['problems'], 'code'))->toBe([Problem::EXPRESSIBLE_PART_SKIPPED])
        ->and($writes[0]['problems'][0]['where'])->toBe('/page/margins/header')
        ->and($writes[1]['ok'])->toBeTrue()
        ->and($result->outcome)->toBe(Outcome::Ready)
        ->and(marginsOf((string) $result->draft))->toBe(['header' => 0])
        ->and($model->requests())->toHaveCount(1);
});

it('does not take a changed header distance for the doable part: "no margins" is zero', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([[reportSharedSideMargins(), headerToZero(['header' => 18])], [headerToZero()], 'Done.']);

    $result = editDocument($store, S4_ASK);

    $writes = $model->resultsOf(DocsTools::WRITE_DRAFT);
    expect($writes[0]['ok'])->toBeFalse()
        ->and(array_column($writes[0]['problems'], 'code'))->toBe([Problem::EXPRESSIBLE_PART_SKIPPED])
        ->and(marginsOf((string) $result->draft))->toBe(['header' => 0]);
});

it('tells the model, when it reports the limit, that part of the request can still be done', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([[reportSharedSideMargins()], [headerToZero()], 'Done.']);

    $result = editDocument($store, S4_ASK);

    $report = $model->resultsOf(DocsTools::REPORT_LIMIT)[0];
    expect($report['ok'])->toBeTrue()
        ->and($report['note'])->toContain('CAN be done')
        ->and(array_column($report['problems'] ?? [], 'code'))->toBe([Problem::EXPRESSIBLE_PART_SKIPPED])
        ->and($result->outcome)->toBe(Outcome::Ready)
        ->and($model->requests())->toHaveCount(1);
});

it('pushes back a bounded number of times, then hands back the limit honestly', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([[reportSharedSideMargins()], 'Done.', 'Still done.', 'Never reached.']);

    $result = editDocument($store, S4_ASK);
    $limit = docsAgent()->catalog()->get('side-margins-shared');

    expect($model->requests())->toHaveCount(1 + DocsAgent::PUSHBACKS)
        ->and($result->outcome)->toBe(Outcome::Limit)
        ->and($result->draft)->toBeNull()
        ->and($store->draft())->toBeNull()
        ->and($result->message)->toBe($limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]));
});

it('does the footer\'s doable part the same way', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([
        [reportSharedSideMargins()],
        'Done.',
        [ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['margins' => ['footer' => 0]]]])],
        'Done.',
    ]);

    $result = editDocument($store, 'Use no margins for the footer.');

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and(marginsOf((string) $result->draft))->toBe(['footer' => 0])
        ->and(lastUserMessage($model, 1))->toContain('page.margins.footer')
        ->not->toContain('page.margins.header');
});

it('asks nothing of a document with no header: the header distance would change nothing anyone sees', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::bodyLetterhead());
    $model = ScriptedModel::install([[reportSharedSideMargins()], 'Done.', 'Never reached.']);

    $result = editDocument($store, S4_ASK);

    expect($result->outcome)->toBe(Outcome::Limit)
        ->and($model->requests())->toHaveCount(1);
});

it('asks for the doable part only when the request names it, narrowly', function (string $request, bool $header, bool $footer): void {
    [$headerPart, $footerPart] = docsAgent()->catalog()->get('side-margins-shared')->expressible;

    expect(Asked::forAny($request, $headerPart->askedBy))->toBe($header)
        ->and(Asked::forAny($request, $footerPart->askedBy))->toBe($footer);
})->with([
    'S4' => [S4_ASK, true, false],
    'Moic\'s request' => ['Use this file as a letterhead template with no margins for the header.', true, false],
    'header margin to zero' => ['Set the header margin to 0.', true, false],
    'header margin to a number that is not zero' => ['Set the header margin to 0.5 inches.', false, false],
    'the footer' => ['Use no margins for the footer.', false, true],
    'both' => ['No margins for the header or for the footer: no margins for the footer either.', true, true],
    'side margins for the header' => ['Set the side margins to zero for the header.', false, false],
    'negated' => ["Don't use no margins for the header.", false, false],
    'unrelated' => ['Make the logo bigger.', false, false],
]);

it('refuses, in the self-check, a file that does not hold a required doable part', function (): void {
    $source = Source::fromStore(new InMemoryDocumentStore(Letterheads::headerLetterhead()));
    $parts = docsAgent()->catalog()->get('side-margins-shared')->expressible;

    $read = $source->read;
    $read['title'] = 'Changed';
    $problems = SelfCheck::run($source, $source->named, $source->images, Agent::toBytes($read), [], false, [], [], [$parts[0]]);

    $read['page']['margins'] = ['header' => 0];
    $fixed = SelfCheck::run($source, $source->named, $source->images, Agent::toBytes($read), [], false, [], [], [$parts[0]]);

    expect(array_map(fn (Problem $p): string => $p->code.' '.$p->where, $problems))->toBe(['expressible_part_skipped /page/margins/header'])
        ->and($fixed)->toBe([]);
});

it('tells the model, in the system prompt, to do the doable part and still report the limit', function (): void {
    $store = new InMemoryDocumentStore(Letterheads::headerLetterhead());
    $model = ScriptedModel::install([[reportSharedSideMargins(), headerToZero()], 'Done.']);

    editDocument($store, S4_ASK);

    $shown = shownToModel($model);
    expect($shown)->toContain('Reporting a limit never ends the edit by itself')
        ->toContain('set `page.margins.header` to 0')
        ->toContain('set `page.margins.footer` to 0');
});
