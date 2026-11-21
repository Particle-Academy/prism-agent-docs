<?php

declare(strict_types=1);

use LastWord\Agent;
use Prism\AgentDocs\Pictures\Picture;
use Prism\AgentDocs\Pictures\PictureRegistry;
use Tests\Support\Letterheads;

/*
| Every picture, wherever it is. The document below puts a picture in each
| place last-word can hold one, and the registry must name them all.
*/

it('registers a picture in every place a document can hold one', function (): void {
    $p = fn (int $n): string => Letterheads::dataUrl(Letterheads::png($n, 1, [$n, 0, 0]));
    $image = fn (int $n): array => ['type' => 'image', 'src' => $p($n)];

    $doc = [
        'blocks' => [
            $image(1),
            ['type' => 'table', 'rows' => [['cells' => [['blocks' => [$image(2)]]]]]],
            ['type' => 'quote', 'blocks' => [$image(3)]],
            ['type' => 'paragraph', 'runs' => [['text' => 'Noted'], ['text' => '', 'footnote' => ['blocks' => [$image(4)]]]]],
            ['type' => 'pageBreak', 'page' => ['orientation' => 'landscape', 'header' => ['blocks' => [$image(5)]]]],
        ],
        'page' => [
            'header' => ['blocks' => [$image(6)]],
            'footer' => ['blocks' => [$image(7)]],
            'firstHeader' => ['blocks' => [$image(8)]],
            'firstFooter' => ['blocks' => [$image(9)]],
            'evenHeader' => ['blocks' => [$image(10)]],
            'evenFooter' => ['blocks' => [$image(11)]],
        ],
    ];
    expect(Agent::validate($doc))->toBe([]);

    $registered = PictureRegistry::register(Agent::read(Agent::toBytes($doc)));

    expect($registered->images)->toHaveCount(11)
        ->and(json_encode($registered->named))->not->toContain('data:image')
        ->and(array_map(fn (Picture $pic): string => $pic->part, $registered->pictures))->toEqualCanonicalizing([
            '/blocks', '/blocks', '/blocks', '/blocks',
            '/blocks/4/page/header',
            '/page/header', '/page/footer', '/page/firstHeader', '/page/firstFooter', '/page/evenHeader', '/page/evenFooter',
        ]);
});

it('gives identical pictures one name', function (): void {
    $logo = Letterheads::dataUrl(Letterheads::logo());
    $registered = PictureRegistry::register([
        'blocks' => [['type' => 'image', 'src' => $logo]],
        'page' => ['header' => ['blocks' => [['type' => 'image', 'src' => $logo]]]],
    ]);

    expect(array_keys($registered->images))->toBe(['picture-1'])
        ->and($registered->pictures)->toHaveCount(2)
        ->and($registered->images['picture-1'])->toBe(Letterheads::logo());
});

it('leaves a src that is not a picture data URL alone', function (): void {
    $registered = PictureRegistry::register(['blocks' => [['type' => 'image', 'src' => 'https://example.com/x.png']]]);

    expect($registered->images)->toBe([])
        ->and($registered->named['blocks'][0]['src'])->toBe('https://example.com/x.png');
});
