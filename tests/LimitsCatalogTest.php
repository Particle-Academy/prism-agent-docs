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
        foreach ($limit->sources as $source) {
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
