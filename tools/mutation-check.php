<?php

declare(strict_types=1);

/*
 * Mutation check: break each seam the agent relies on, and prove a test fails.
 *
 * A test that passes is a claim; this makes it a check. Each mutant below is
 * the defect a seam exists to prevent (scenario 1's body-only picture list,
 * scenario 2's draft-as-source, scenario 3's no-op draft, the owner's two
 * rules), applied to the real source. The named tests must then FAIL. A
 * mutant the suite survives is a seam nothing guards, and this exits non-zero.
 *
 * Each search string must occur exactly once, so a refactor that moves the
 * code fails this loudly instead of quietly checking nothing.
 *
 *   php tools/mutation-check.php            # every mutant
 *   php tools/mutation-check.php M2 M5      # some
 *
 * Runs Pest one test file at a time, never in parallel, and restores every
 * file it touched, even when interrupted by an exception.
 */

$root = dirname(__DIR__);

$mutants = [
    'M1' => [
        'seam' => 'picture registration covers every part (scenario 1)',
        'mutation' => 'register only the BODY\'s pictures, as Moic\'s host did',
        'edits' => [['src/Pictures/PictureRegistry.php',
            '$bytes = self::decode($src);',
            '$bytes = $part === \Prism\AgentDocs\Support\DocumentTree::BODY ? self::decode($src) : null;']],
        'tests' => ['tests/Scenario1HeaderPicturesTest.php', 'tests/PictureRegistryTest.php'],
    ],
    'M2' => [
        'seam' => 'the source is the saved file (scenario 2)',
        'mutation' => 'start from the pending draft when there is one',
        'edits' => [['src/Source.php',
            '$bytes = $store->saved();',
            '$bytes = $store->draft() ?? $store->saved();']],
        'tests' => ['tests/Scenario2SavedNotDraftTest.php'],
    ],
    'M3' => [
        'seam' => 'a draft that changes nothing is refused (scenario 3)',
        'mutation' => 'drop the no-change check',
        'edits' => [['src/Check/SelfCheck.php',
            'if ($problems === [] && $source->exists() && Agent::equivalent($produced, $source->read)) {',
            'if (false) {']],
        'tests' => ['tests/Scenario3LimitsTest.php'],
    ],
    'M4' => [
        'seam' => 'a reported limit reaches the person (scenario 3)',
        'mutation' => 'acknowledge report_limit but record nothing',
        'edits' => [['src/Workspace.php',
            '$this->limits[] = [\'limit\' => $limit, \'alternative\' => $alternative];',
            '']],
        'tests' => ['tests/Scenario3LimitsTest.php'],
    ],
    'M5' => [
        'seam' => 'an empty file is refused unless the host allows it (scenario 1)',
        'mutation' => 'drop the emptiness check',
        'edits' => [['src/Check/SelfCheck.php',
            'if (! $allowEmpty && ! DocumentTree::hasContent($produced)) {',
            'if (false) {']],
        'tests' => ['tests/Scenario1HeaderPicturesTest.php', 'tests/SelfCheckTest.php'],
    ],
    'M6' => [
        'seam' => 'placed pictures are in the file, same part, same bytes (self-check)',
        'mutation' => 'skip the picture comparison',
        'edits' => [['src/Check/SelfCheck.php',
            'array_push($problems, ...self::pictures($intended, $images, $produced));',
            '']],
        'tests' => ['tests/SelfCheckTest.php'],
    ],
    'M7' => [
        'seam' => 'a band the draft leaves out is carried from the saved file (owner rule 1)',
        'mutation' => 'stop carrying it (the check still expects it)',
        'edits' => [['src/Workspace.php',
            '$page[$band] = $savedPage[$band];',
            '']],
        'tests' => ['tests/OwnerRulesTest.php'],
    ],
    'M8' => [
        'seam' => 'owner rule 1 holds even when BOTH guards are gone',
        'mutation' => 'stop carrying the band AND skip the band check',
        'edits' => [
            ['src/Workspace.php', '$page[$band] = $savedPage[$band];', ''],
            ['src/Check/SelfCheck.php', 'array_push($problems, ...self::bands($source, $produced, $keptBands));', ''],
        ],
        'tests' => ['tests/OwnerRulesTest.php'],
    ],
    'M9' => [
        'seam' => 'the person\'s message is composed, not the model\'s (owner rule 2)',
        'mutation' => 'hand back the model\'s closing text',
        'edits' => [
            ['src/DocsAgent.php',
                '$session->send(Prompt::opening($request, $workspace));',
                '$said = $session->send(Prompt::opening($request, $workspace))->text();'],
            ['src/DocsAgent.php',
                '$result = DocsResult::from($workspace);',
                '$result = DocsResult::from($workspace); $result = new DocsResult($result->outcome, $said ?? $result->message, $result->draft, $result->limits, $result->problems);'],
        ],
        'tests' => ['tests/OwnerRulesTest.php'],
    ],
    'M10' => [
        'seam' => 'a refused last write discards an earlier passing draft',
        'mutation' => 'keep the earlier draft',
        'edits' => [['src/Workspace.php', '$this->draft = null;', '']],
        'tests' => ['tests/Scenario1HeaderPicturesTest.php'],
    ],
];

$only = array_slice($argv, 1);
$selected = $only === [] ? $mutants : array_intersect_key($mutants, array_flip($only));
if ($selected === []) {
    fwrite(STDERR, "No such mutant.\n");
    exit(2);
}

$php = PHP_BINARY;
$pest = $root.'/vendor/bin/pest';
$survivors = [];

foreach ($selected as $id => $mutant) {
    $originals = [];
    try {
        foreach ($mutant['edits'] as [$file, $search, $replace]) {
            $path = $root.'/'.$file;
            // The CURRENT text, so a second edit to one file builds on the first.
            $code = (string) file_get_contents($path);
            $originals[$path] ??= $code;
            $count = substr_count($code, $search);
            if ($count !== 1) {
                throw new RuntimeException("{$id}: expected the search string exactly once in {$file}, found {$count}. Re-point the mutant at the moved code.");
            }
            file_put_contents($path, str_replace($search, $replace, $code));
        }

        $killed = false;
        $by = '';
        foreach ($mutant['tests'] as $test) {
            $output = [];
            exec(escapeshellarg($php).' '.escapeshellarg($pest).' '.escapeshellarg($root.'/'.$test).' --colors=never 2>&1', $output, $status);
            if ($status !== 0) {
                $killed = true;
                // Name the test that caught it, so a kill by a crash is told
                // apart from a kill by the assertion the seam is about.
                foreach ($output as $line) {
                    if (str_contains($line, 'FAILED') && str_contains($line, '>')) {
                        $by = trim(substr($line, strpos($line, '>') + 1));
                        break;
                    }
                }
                break;
            }
        }
    } finally {
        foreach ($originals as $path => $code) {
            file_put_contents($path, $code);
        }
    }

    printf("%-4s %-8s %s -- %s\n", $id, $killed ? 'killed' : 'SURVIVED', $mutant['seam'], $mutant['mutation']);
    if ($by !== '') {
        printf("     by: %s\n", $by);
    }
    if (! $killed) {
        $survivors[] = $id;
    }
}

if ($survivors !== []) {
    fwrite(STDERR, 'Survived: '.implode(', ', $survivors).". A seam nothing guards.\n");
    exit(1);
}

echo count($selected)." mutants, all killed.\n";
