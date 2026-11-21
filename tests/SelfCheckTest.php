<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Check\SelfCheck;
use Prism\AgentDocs\InMemoryDocumentStore;
use Prism\AgentDocs\Source;
use Tests\Support\Letterheads;

/*
| The self-check on its own: written bytes in, problems out. These feed it
| files the writer did NOT produce from the intended document, which is the
| case it exists for: a check that only ever sees agreeing input checks nothing.
*/

function letterheadSource(): Source
{
    return Source::fromStore(new InMemoryDocumentStore(Letterheads::headerLetterheadWithBody()));
}

it('refuses a file whose kept header is not the saved header', function (): void {
    $source = letterheadSource();
    $intended = $source->named;
    $written = Agent::toBytes(['blocks' => $source->read['blocks'], 'page' => ['footer' => ['center' => '{page}'], 'header' => ['left' => 'Something else']]]);

    $problems = SelfCheck::run($source, $intended, $source->images, $written, ['header', 'footer'], false);

    expect(array_map(fn (Problem $p): string => $p->code.' '.$p->where, $problems))->toContain('band_changed /page/header');
});

it('refuses a file that lost a kept header entirely', function (): void {
    $source = letterheadSource();
    $written = Agent::toBytes(['blocks' => $source->read['blocks'], 'page' => ['footer' => ['center' => '{page}']]]);

    $problems = SelfCheck::run($source, ['blocks' => $source->read['blocks']], $source->images, $written, ['header', 'footer'], false);

    expect(array_map(fn (Problem $p): string => $p->code, $problems))->toBe([Problem::BAND_CHANGED]);
});

it('refuses a file whose header picture is not byte-identical', function (): void {
    $source = letterheadSource();
    $other = Letterheads::png(8, 4, [200, 30, 31]); // one channel off by one
    $written = Agent::toBytes(['blocks' => [], 'page' => ['header' => ['blocks' => [
        ['type' => 'image', 'src' => Letterheads::dataUrl($other)],
        ['type' => 'image', 'src' => Letterheads::dataUrl(Letterheads::divider())],
    ]]]]);
    $intended = ['blocks' => [], 'page' => ['header' => ['blocks' => [
        ['type' => 'image', 'src' => 'image:picture-1'],
        ['type' => 'image', 'src' => 'image:picture-2'],
    ]]]];

    $problems = SelfCheck::run($source, $intended, $source->images, $written, [], false);

    expect($problems)->toHaveCount(1)
        ->and($problems[0]->code)->toBe(Problem::PICTURE_MISSING)
        ->and($problems[0]->where)->toBe('/page/header/blocks/0');
});

it('refuses a picture that moved from the header into the body', function (): void {
    $source = letterheadSource();
    $written = Agent::toBytes(['blocks' => [['type' => 'image', 'src' => Letterheads::dataUrl(Letterheads::logo())]]]);
    $intended = ['blocks' => [], 'page' => ['header' => ['blocks' => [['type' => 'image', 'src' => 'image:picture-1']]]]];

    $problems = SelfCheck::run($source, $intended, $source->images, $written, [], false);

    expect($problems[0]->code)->toBe(Problem::PICTURE_MISSING);
});

it('counts a header picture or a footer page number as content', function (): void {
    $source = Source::fromStore(new InMemoryDocumentStore(null));

    $headerOnly = Agent::toBytes(['blocks' => [], 'page' => ['header' => ['blocks' => [['type' => 'image', 'src' => Letterheads::dataUrl(Letterheads::logo())]]]]]);
    $footerOnly = Agent::toBytes(['blocks' => [], 'page' => ['footer' => ['center' => '{page}']]]);
    $nothing = Agent::toBytes(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => '   ']]]]]);

    expect(SelfCheck::run($source, [], [], $headerOnly, [], false))->toBe([])
        ->and(SelfCheck::run($source, [], [], $footerOnly, [], false))->toBe([])
        ->and(SelfCheck::run($source, [], [], $nothing, [], false)[0]->code)->toBe(Problem::EMPTY_DOCUMENT);
});

it('refuses bytes that are not a document', function (): void {
    $problems = SelfCheck::run(letterheadSource(), [], [], 'not a docx', [], false);

    expect($problems[0]->code)->toBe(Problem::UNREADABLE);
});
