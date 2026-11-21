<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Prism\AgentDocs\Limits\LimitCatalog;

/*
| The limits are data with a citation each, and the citation is CHECKED: every
| quote must be in the installed last-word, word for word (whitespace aside).
| When last-word lifts or rewords a limit, this fails, and the catalogue is
| re-read against the new docs rather than left to tell people something the
| writer no longer says.
*/

it('cites, word for word, a document in the installed last-word for every limit', function (): void {
    $root = InstalledVersions::getInstallPath('particle-academy/last-word');
    expect($root)->not->toBeNull();
    $normalise = fn (string $s): string => (string) preg_replace('/\s+/', ' ', $s);

    foreach (LimitCatalog::load()->all() as $limit) {
        $sources = array_merge($limit->sources, ...array_map(fn ($part): array => $part->sources, $limit->expressible));
        expect($sources)->not->toBe([]);
        foreach ($sources as $source) {
            expect($source['package'])->toBe('particle-academy/last-word');
            $path = $root.'/'.$source['file'];
            expect(is_file($path))->toBeTrue("{$limit->id}: {$source['file']} is not in the installed last-word");
            expect($normalise((string) file_get_contents($path)))
                ->toContain($normalise($source['quote']));
        }
    }
});

it('has the two limits behind scenario 3', function (): void {
    $catalog = LimitCatalog::load();

    expect($catalog->has('side-margins-shared'))->toBeTrue()
        ->and($catalog->has('no-bleed-past-margin'))->toBeTrue()
        ->and($catalog->get('no-bleed-past-margin')->council)->toContain('CC-fancy-0055');
});

it('gives every limit a plain statement and at least one alternative', function (): void {
    foreach (LimitCatalog::load()->all() as $limit) {
        expect($limit->statement)->toEndWith('.')
            ->and($limit->alternatives)->not->toBe([])
            ->and($limit->statement)->not->toContain('`');
    }
});

it('names the paths each margin limit\'s alternatives would change, so they are offered and never applied', function (): void {
    $catalog = LimitCatalog::load();

    expect($catalog->get('side-margins-shared')->alternativePaths)->toBe(['page.margins.left', 'page.margins.right'])
        ->and($catalog->get('no-bleed-past-margin')->alternativePaths)->toBe(['page.margins.top', 'page.margins.right', 'page.margins.bottom', 'page.margins.left']);
    foreach ($catalog->all() as $limit) {
        if ($limit->alternativePaths !== []) {
            expect($limit->applied)->toBeString()->toEndWith('.');
        }
    }
});

it('names, as data, the parts of a margin limit a .docx can still do, none of them an alternative', function (): void {
    $catalog = LimitCatalog::load();

    foreach (['side-margins-shared', 'no-bleed-past-margin'] as $id) {
        $limit = $catalog->get($id);
        expect(array_map(fn ($part): string => $part->path, $limit->expressible))->toBe(['page.margins.header', 'page.margins.footer'], $id);
        foreach ($limit->expressible as $part) {
            expect($part->value)->toBe(0)
                ->and($part->instruction)->toEndWith('.')
                ->and($part->done)->toEndWith('.')->not->toContain('`')->not->toContain('page.margins')
                ->and($part->askedBy)->not->toBe([])
                ->and($part->sources)->not->toBe([])
                ->and(array_intersect([$part->path], $limit->alternativePaths))->toBe([]);
        }
    }
});

it('refuses an expressible part that is an alternative path, so doing it could apply an offer', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'limits');
    $source = ['package' => 'p', 'file' => 'f', 'section' => 's', 'quote' => 'q'];
    file_put_contents($path, json_encode(['limits' => [
        ['id' => 'x', 'statement' => 'X.', 'appliesWhen' => 'x', 'alternatives' => ['y'], 'sources' => [$source], 'alternativePaths' => ['page.margins.left'], 'applied' => 'Done.'],
        ['id' => 'z', 'statement' => 'Z.', 'appliesWhen' => 'z', 'alternatives' => ['y'], 'sources' => [$source], 'expressible' => [
            ['path' => 'page.margins.left', 'value' => 0, 'done' => 'Done.', 'instruction' => 'Do it.', 'askedBy' => ['no left margin'], 'bands' => ['header'], 'sources' => [$source]],
        ]],
    ]]));

    try {
        expect(fn () => LimitCatalog::load($path))->toThrow(RuntimeException::class, 'never applied');
    } finally {
        unlink($path);
    }
});

it('refuses an expressible part without a source or a known band', function (array $part): void {
    $path = tempnam(sys_get_temp_dir(), 'limits');
    $source = ['package' => 'p', 'file' => 'f', 'section' => 's', 'quote' => 'q'];
    file_put_contents($path, json_encode(['limits' => [
        ['id' => 'x', 'statement' => 'X.', 'appliesWhen' => 'x', 'alternatives' => ['y'], 'sources' => [$source], 'expressible' => [array_replace(
            ['path' => 'page.margins.header', 'value' => 0, 'done' => 'Done.', 'instruction' => 'Do it.', 'askedBy' => ['no header margin'], 'bands' => ['header'], 'sources' => [$source]],
            $part,
        )]],
    ]]));

    try {
        expect(fn () => LimitCatalog::load($path))->toThrow(RuntimeException::class, 'expressible');
    } finally {
        unlink($path);
    }
})->with([
    'no source' => [['sources' => []]],
    'unknown band' => [['bands' => ['sidebar']]],
    'no phrases' => [['askedBy' => []]],
    'not a number' => [['value' => 'zero']],
    'no done sentence' => [['done' => null]],
    'a done sentence that is not plain' => [['done' => 'Set `page.margins.header` to 0']],
]);

it('refuses an alternative path that is not under page', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'limits');
    file_put_contents($path, json_encode(['limits' => [['id' => 'x', 'statement' => 'X.', 'appliesWhen' => 'x', 'alternatives' => ['y'], 'sources' => [['package' => 'p', 'file' => 'f', 'section' => 's', 'quote' => 'q']], 'alternativePaths' => ['blocks'], 'applied' => 'Done.']]]));

    try {
        expect(fn () => LimitCatalog::load($path))->toThrow(RuntimeException::class, 'dotted path under page');
    } finally {
        unlink($path);
    }
});

it('refuses a catalogue entry without a source', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'limits');
    file_put_contents($path, json_encode(['limits' => [['id' => 'x', 'statement' => 'X.', 'appliesWhen' => 'x', 'alternatives' => ['y'], 'sources' => []]]]));

    try {
        expect(fn () => LimitCatalog::load($path))->toThrow(RuntimeException::class, 'at least one source');
    } finally {
        unlink($path);
    }
});

it('lists every catalogued limit in the README', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__).'/README.md');

    foreach (LimitCatalog::load()->ids() as $id) {
        expect($readme)->toContain('| `'.$id.'` |');
    }
});
