<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\DocsResult;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Limits\LimitCatalog;
use Prism\AgentDocs\Outcome;
use Prism\AgentDocs\Tools\DocsTools;
use Tests\Support\Letterheads;
use Tests\Support\ScriptedModel;

/*
|--------------------------------------------------------------------------
| G-316 live run 01a2007d: never offer what the document already is
|--------------------------------------------------------------------------
|
| S4 and R2 passed every structural check and failed the judge: the model
| chose "keep the header art inside the page margins, as it is now", and the
| person was offered the layout they already had. Which alternative is
| offered is now decided in code: the model's choice unless it is the current
| state, else the closest one that would change something, else none.
|
*/

const CURRENT_STATE_ASK = 'Use no margins for the header.';

function reportSideMarginsChoosing(int $alternative): array
{
    return [ScriptedModel::call(DocsTools::REPORT_LIMIT, ['limit' => 'side-margins-shared', 'alternative' => $alternative])];
}

/** The header letterhead with its side margins already at zero. */
function zeroSideMarginLetterhead(): string
{
    return Agent::toBytes([
        'blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Dear Ms Example,']]]],
        'page' => [
            'margins' => ['top' => 72, 'right' => 0, 'bottom' => 72, 'left' => 0],
            'header' => ['blocks' => [['type' => 'image', 'src' => Letterheads::dataUrl(Letterheads::logo()), 'widthPx' => 96, 'heightPx' => 48]]],
        ],
    ]);
}

it('offers the substantive alternative when the model chose the current state', function (): void {
    $limit = LimitCatalog::load()->get('side-margins-shared');
    ScriptedModel::install([reportSideMarginsChoosing(1), 'Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::bodyLetterhead()), CURRENT_STATE_ASK);

    expect($result->outcome)->toBe(Outcome::Limit)
        ->and($result->limits[0]['alternative'])->toBe(0)
        ->and($result->message)->toBe($limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]))
        ->and($result->message)->not->toContain('as it is now');
});

it('keeps a substantive alternative the model chose', function (): void {
    ScriptedModel::install([reportSideMarginsChoosing(0), 'Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::bodyLetterhead()), CURRENT_STATE_ASK);

    expect($result->limits[0]['alternative'])->toBe(0);
});

it('offers no alternative when every one is what the document already is', function (int $chosen): void {
    // Side margins already zero: "set them to zero" offers nothing, and
    // "keep it as it is now" never does.
    $limit = LimitCatalog::load()->get('side-margins-shared');
    ScriptedModel::install([reportSideMarginsChoosing($chosen), 'Done.']);

    $result = editDocument(new InMemoryDocumentStore(zeroSideMarginLetterhead()), 'Make the header art run past the side margins while the body keeps them.');

    expect($result->limits[0]['alternative'])->toBeNull()
        ->and($result->message)->toBe($limit->statement)
        ->and($result->message)->not->toContain(trim(DocsResult::OFFER));
})->with([0, 1]);

it('says the doable part and the substantive offer together (S4 on the header letterhead)', function (): void {
    $limit = LimitCatalog::load()->get('side-margins-shared');
    ScriptedModel::install([[...reportSideMarginsChoosing(1), ScriptedModel::call(DocsTools::WRITE_DRAFT, ['document' => ['page' => ['margins' => ['header' => 0]]]])], 'Done.']);

    $result = editDocument(new InMemoryDocumentStore(Letterheads::headerLetterhead()), CURRENT_STATE_ASK);

    expect($result->outcome)->toBe(Outcome::Ready)
        ->and($result->message)->toBe('The draft is ready. The header now starts at the top edge of the page. '
            .$limit->statement.DocsResult::OFFER.lcfirst($limit->alternatives[0]));
});

it('compares numbers by value and never counts an absent value as held', function (): void {
    $limit = LimitCatalog::load()->get('side-margins-shared');

    expect($limit->isCurrentState(0, ['page' => ['margins' => ['left' => 0.0, 'right' => 0]]]))->toBeTrue()
        ->and($limit->isCurrentState(0, ['page' => ['margins' => ['left' => 0]]]))->toBeFalse()
        ->and($limit->isCurrentState(0, ['page' => ['margins' => ['left' => 36, 'right' => 36]]]))->toBeFalse()
        ->and($limit->offer(1, ['page' => ['margins' => ['left' => 36, 'right' => 36]]]))->toBe(0)
        ->and($limit->isCurrentState(0, []))->toBeFalse()
        ->and($limit->isCurrentState(1, []))->toBeTrue()
        ->and($limit->offer(1, []))->toBe(0)
        ->and($limit->offer(0, ['page' => ['margins' => ['left' => 0, 'right' => 0]]]))->toBeNull();
    // An alternative that moves content is never the current state.
    expect(LimitCatalog::load()->get('band-no-footnote')->offer(1, []))->toBe(1);
});

it('marks every "as it is now" alternative in the catalogue as changing nothing', function (): void {
    foreach (LimitCatalog::load()->all() as $limit) {
        foreach ($limit->alternatives as $i => $alternative) {
            if (str_contains(strtolower($alternative), 'as it is now')) {
                expect($limit->alternativeChanges[$i] ?? null)->toBe([], "{$limit->id} alternative {$i}");
            }
        }
    }
});

it('refuses alternativeChanges that do not line up with the alternatives or their paths', function (mixed $changes): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'limits');
    file_put_contents($path, json_encode(['limits' => [['id' => 'x', 'kind' => 'format', 'statement' => 'X.', 'appliesWhen' => 'x',
        'alternatives' => ['a', 'b'], 'alternativePaths' => ['page.margins.left'], 'applied' => 'Done.',
        'sources' => [['package' => 'p', 'file' => 'f', 'section' => 's', 'quote' => 'q']], 'alternativeChanges' => $changes]]]));

    try {
        expect(fn () => LimitCatalog::load($path))->toThrow(RuntimeException::class, 'alternativeChanges');
    } finally {
        unlink($path);
    }
})->with([
    'one entry for two alternatives' => [[['page.margins.left' => 0]]],
    'a path that is not an alternative path' => [[['page.margins.top' => 0], null]],
    'not a number' => [[['page.margins.left' => 'zero'], null]],
    'a list, not an object' => [[[0], null]],
]);
