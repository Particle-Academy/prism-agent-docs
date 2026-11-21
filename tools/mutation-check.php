<?php

declare(strict_types=1);

/*
 * Mutation check: break each seam the agent relies on, and prove a test fails.
 *
 * A test that passes is a claim; this makes it a check. Each mutant below is
 * the defect a seam exists to prevent (scenario 1's body-only picture list,
 * scenario 2's empty-draft-as-source, the host's working copy, scenario 3's
 * no-op draft, the owner's two rules, the host's bounds, and from Moic's live
 * test of 0.1.0: an alternative offered but applied, a first-page band nobody
 * asked for), applied to the real source. The named tests must then FAIL. A
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

/** @var array<string, array{seam: string, mutation: string, edits: list<array{0: string, 1: string, 2: string}>, tests: list<string>}> $mutants */
$mutants = [
    'M1' => [
        'seam' => 'picture registration covers every part (scenario 1)',
        'mutation' => 'register only the BODY\'s pictures, as Moic\'s host did',
        'edits' => [['src/Pictures/PictureRegistry.php',
            '$bytes = self::decode($src);',
            '$bytes = $part === \Prism\AgentDocs\Support\DocumentTree::BODY ? self::decode($src) : null;']],
        'tests' => ['tests/Scenario1HeaderPicturesTest.php', 'tests/PictureRegistryTest.php'],
    ],
    'M2a' => [
        'seam' => 'the store default continues a draft that holds something (Moic\'s design)',
        'mutation' => 'always start from the saved file',
        'edits' => [['src/WorkingCopy.php',
            'if ($draft !== null && $draft !== \'\' && self::holdsSomething($draft)) {',
            'if (false) {']],
        'tests' => ['tests/WorkingCopyTest.php'],
    ],
    'M2b' => [
        'seam' => 'an empty draft is never a starting point (scenario 2)',
        'mutation' => 'always start from the draft, even an empty one',
        'edits' => [['src/WorkingCopy.php',
            'if ($draft !== null && $draft !== \'\' && self::holdsSomething($draft)) {',
            'if ($draft !== null) {']],
        'tests' => ['tests/Scenario2SavedNotDraftTest.php', 'tests/WorkingCopyTest.php'],
    ],
    'M2c' => [
        'seam' => 'an explicit host working copy is used verbatim',
        'mutation' => 'revise something other than the bytes the host gave',
        'edits' => [['src/DocsAgent.php',
            '$copy = $document instanceof WorkingCopy ? $document : WorkingCopy::fromStore($document);',
            '$copy = $document instanceof WorkingCopy ? new WorkingCopy(null, $document->baseVersion) : WorkingCopy::fromStore($document);']],
        'tests' => ['tests/WorkingCopyTest.php'],
    ],
    'M2d' => [
        'seam' => 'the base version reaches the result unchanged',
        'mutation' => 'hand back a version computed from the bytes instead of the working copy\'s',
        'edits' => [['src/Source.php',
            'return new self($bytes, $read, $registered->named, $registered->images, $registered->pictures, $unrepresented, $copy->baseVersion);',
            'return new self($bytes, $read, $registered->named, $registered->images, $registered->pictures, $unrepresented, WorkingCopy::versionOf($bytes));']],
        'tests' => ['tests/WorkingCopyTest.php'],
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
        'seam' => 'a band the draft leaves out is carried from the working copy (owner rule 1)',
        'mutation' => 'stop carrying it (the check still expects it)',
        'edits' => [['src/Workspace.php',
            '$page[$band] = $basePage[$band];',
            '']],
        'tests' => ['tests/OwnerRulesTest.php'],
    ],
    'M8' => [
        'seam' => 'owner rule 1 holds even when BOTH guards are gone',
        'mutation' => 'stop carrying the band AND skip the band check',
        'edits' => [
            ['src/Workspace.php', '$page[$band] = $basePage[$band];', ''],
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
                '$result = DocsResult::from($workspace); $result = new DocsResult($result->outcome, $said ?? $result->message, $result->baseVersion, $result->draft, $result->limits, $result->problems);'],
        ],
        'tests' => ['tests/OwnerRulesTest.php'],
    ],
    'M10' => [
        'seam' => 'a refused last write discards an earlier passing draft',
        'mutation' => 'keep the earlier draft',
        'edits' => [['src/Workspace.php', '$this->draft = null;', '']],
        'tests' => ['tests/Scenario1HeaderPicturesTest.php'],
    ],
    'M11' => [
        'seam' => 'an unreadable file gives the person fixed words',
        'mutation' => 'show the reader\'s exception text',
        'edits' => [['src/DocsAgent.php',
            'return DocsResult::failed(DocsResult::UNREADABLE, $version);',
            'return DocsResult::failed($e->getMessage(), $version);']],
        'tests' => ['tests/HostLimitsTest.php'],
    ],
    'M12' => [
        'seam' => 'the picture count is capped by the host, not by the file',
        'mutation' => 'raise maxImages to fit the file and drop the refusal, as phase A did',
        'edits' => [
            ['src/DocsAgent.php', 'if ($workspace->pictureCount() > $options->maxImages) {', 'if (false) {'],
            ['src/Workspace.php', '[\'maxImages\' => $this->options->maxImages]', '[\'maxImages\' => max($this->options->maxImages, count($this->writerImages))]'],
        ],
        'tests' => ['tests/HostLimitsTest.php'],
    ],
    'M13' => [
        'seam' => 'a request over the size cap is refused before the model',
        'mutation' => 'drop the cap',
        'edits' => [['src/DocsAgent.php', 'if (strlen($request) > $options->maxRequestBytes) {', 'if (false) {']],
        'tests' => ['tests/HostLimitsTest.php'],
    ],
    'M14' => [
        'seam' => 'a reported limit\'s alternative is reverted before writing (offer, not apply)',
        'mutation' => 'keep the model\'s change to the alternative\'s paths',
        'edits' => [['src/Workspace.php',
            '$composed = Paths::copy($composed, $this->source->named, $path);',
            '']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M15' => [
        'seam' => 'offer, not apply, holds even when BOTH guards are gone',
        'mutation' => 'keep the change AND skip the self-check of the alternative\'s paths',
        'edits' => [
            ['src/Workspace.php', '$composed = Paths::copy($composed, $this->source->named, $path);', ''],
            ['src/Check/SelfCheck.php', 'array_push($problems, ...self::offeredOnly($source, $produced, $unchangedPaths));', ''],
        ],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M16' => [
        'seam' => 'the self-check refuses a file that makes a reported limit\'s alternative',
        'mutation' => 'skip that check',
        'edits' => [['src/Check/SelfCheck.php', 'array_push($problems, ...self::offeredOnly($source, $produced, $unchangedPaths));', '']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M17' => [
        'seam' => 'a draft written BEFORE the limit is reported is written again without the alternative',
        'mutation' => 'keep the earlier draft as it was',
        'edits' => [['src/Workspace.php', '$again = $this->attempt(...$this->accepted);', '$again = [\'ok\' => true];']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M18' => [
        'seam' => 'the alternative is applied when the request asks for exactly that change',
        'mutation' => 'never read the request as asking',
        'edits' => [['src/Workspace.php', '$this->askedPaths = array_values(array_unique($asked));', '$this->askedPaths = [];']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M19' => [
        'seam' => 'a negated phrase is not an explicit ask',
        'mutation' => 'ignore negation',
        'edits' => [['src/Support/Asked.php', 'if (! self::negated(substr($text, 0, $at))) {', 'if (true) {']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M20' => [
        'seam' => 'the message offers an alternative the draft does not contain (owner rule)',
        'mutation' => 'word every alternative as applied',
        'edits' => [['src/DocsResult.php', 'static fn (array $reported): string => $workspace->alternativeApplied($reported[\'limit\'])', 'static fn (array $reported): string => true']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M21' => [
        'seam' => 'an introduced first-page band becomes the band every page shows',
        'mutation' => 'leave the model\'s firstHeader as it is',
        'edits' => [['src/Workspace.php', 'if (! $this->askedFirstPageOnly && is_array($document[\'page\'] ?? null)) {', 'if (false) {']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M22' => [
        'seam' => 'no unasked first-page band, even when BOTH guards are gone',
        'mutation' => 'leave the firstHeader AND skip the self-check of first-page bands',
        'edits' => [
            ['src/Workspace.php', 'if (! $this->askedFirstPageOnly && is_array($document[\'page\'] ?? null)) {', 'if (false) {'],
            ['src/Check/SelfCheck.php', 'array_push($problems, ...self::newFirstPageBands($source, $produced, $noNewBands));', ''],
        ],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M23' => [
        'seam' => 'the self-check refuses a first-page band the working copy lacked',
        'mutation' => 'skip that check',
        'edits' => [['src/Check/SelfCheck.php', 'array_push($problems, ...self::newFirstPageBands($source, $produced, $noNewBands));', '']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M24' => [
        'seam' => 'a working copy\'s own first-page band is kept when the draft rewrites it',
        'mutation' => 'normalise every first-page band the draft writes',
        'edits' => [['src/Workspace.php', 'if (! array_key_exists($first, $page) || array_key_exists($first, $basePage)) {', 'if (! array_key_exists($first, $page)) {']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M25' => [
        'seam' => 'a first-page band the request asks for is kept',
        'mutation' => 'never read the request as asking for first-page-only',
        'edits' => [['src/Workspace.php', '$this->askedFirstPageOnly = Asked::forAny($request, Asked::FIRST_PAGE_ONLY);', '$this->askedFirstPageOnly = false;']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    'M26' => [
        'seam' => 'the message says a first-page band shows on the first page only',
        'mutation' => 'say nothing',
        'edits' => [['src/DocsResult.php', '$notes[] = self::FIRST_PAGE_ONLY[count($first) > 1 ? \'both\' : $first[0]];', '']],
        'tests' => ['tests/OfferNotApplyTest.php'],
    ],
    // G-316 lab run 01a2002d, scenario S4: a limit is a reason to offer the
    // rest, never to skip the part the format can do.
    'M27' => [
        'seam' => 'the self-check refuses a draft without a requested doable part (expressible_part_skipped)',
        'mutation' => 'skip that check',
        'edits' => [['src/Check/SelfCheck.php', 'array_push($problems, ...self::skipped($produced, $expressible));', '']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M28' => [
        'seam' => 'a run that reported the limit and skipped the doable part is returned to the model',
        'mutation' => 'never push back',
        'edits' => [['src/DocsAgent.php', '$skipped = $workspace->skippedParts();', '$skipped = [];']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M29' => [
        'seam' => 'a doable part is asked only when the document has the band it positions',
        'mutation' => 'ask for the header distance in a document with no header',
        'edits' => [['src/Workspace.php', 'if ($part->applies($document)) {', 'if (true) {']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php', 'tests/Scenario3LimitsTest.php'],
    ],
    'M30' => [
        'seam' => 'a doable part is asked only when the request names it',
        'mutation' => 'ask for every doable part of a reported limit',
        'edits' => [['src/Workspace.php', 'static fn (Expressible $part): bool => Asked::forAny($request, $part->askedBy)', 'static fn (Expressible $part): bool => true']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M31' => [
        'seam' => 'the request is read for the doable part at all',
        'mutation' => 'never read the request as asking for one',
        'edits' => [['src/Workspace.php', 'static fn (Expressible $part): bool => Asked::forAny($request, $part->askedBy)', 'static fn (Expressible $part): bool => false']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M32' => [
        'seam' => 'the doable part is held only at its value ("no margins" is zero)',
        'mutation' => 'accept any value written at the path',
        'edits' => [['src/Limits/Expressible.php', 'return $has && (is_int($value) || is_float($value)) && (float) $value === (float) $this->value;', 'return $has;']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M33' => [
        'seam' => 'report_limit tells the model the doable part is still to do',
        'mutation' => 'say nothing of it',
        'edits' => [['src/Workspace.php', '$skipped = $this->skippedParts();', '$skipped = [];']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M34' => [
        'seam' => 'no doable part is an alternative path, so doing it never applies an offer',
        'mutation' => 'drop the catalogue\'s overlap check',
        'edits' => [['src/Limits/LimitCatalog.php', 'if (in_array($part->path, $offered, true)) {', 'if (false) {']],
        'tests' => ['tests/LimitsCatalogTest.php'],
    ],
    'M35' => [
        'seam' => 'the model is shown each limit\'s doable parts',
        'mutation' => 'leave them out of the prompt',
        'edits' => [['src/Limits/LimitCatalog.php', '$lines[] = sprintf(\'  Can still be done', '$unused = sprintf(\'  Can still be done']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M36' => [
        'seam' => 'a sentence-final "0." reads as zero',
        'mutation' => 'the 0.1.2 reading, which a full stop defeats',
        'edits' => [['src/Support/Asked.php', '0(?!\w|\.\d)/u', '0(?![\w.])/u']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M37' => [
        'seam' => '"0.5" does not read as zero',
        'mutation' => 'read a 0 before any point as zero',
        'edits' => [['src/Support/Asked.php', '0(?!\w|\.\d)/u', '0(?!\w)/u']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    // G-316 live run 01a20049, scenario S3: a doable part only where it shows,
    // and said when it is done.
    'M38' => [
        'seam' => 'a doable part with no band to position is set back (no no-op draft)',
        'mutation' => 'keep the header distance in a document with no header',
        'edits' => [['src/Workspace.php', 'foreach ($this->unshownParts($composed) as $part) {', 'foreach ([] as $part) {']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M39' => [
        'seam' => 'a draft written before the limit is reported is set back too',
        'mutation' => 're-run an earlier draft for the alternative\'s paths only',
        'edits' => [['src/Workspace.php', 'foreach ([...$this->offeredPaths(), ...$unshown] as $path) {', 'foreach ($this->offeredPaths() as $path) {']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M40' => [
        'seam' => 'the message says what the draft did (the catalogue\'s done sentence)',
        'mutation' => 'say nothing of it',
        'edits' => [['src/DocsResult.php', 'implode(\' \', [self::READY, ...$done, ...$notes])', 'implode(\' \', [self::READY, ...$notes])']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M41' => [
        'seam' => 'the done sentence is said only for a change the draft made',
        'mutation' => 'say it whenever the draft holds the value, even the working copy\'s own',
        'edits' => [['src/Workspace.php', '$part->heldBy($drafted) && ! Paths::same($drafted, $this->source->named, $part->path)', '$part->heldBy($drafted)']],
        'tests' => ['tests/Scenario4DoTheDoablePartTest.php'],
    ],
    'M42' => [
        'seam' => 'every doable part has a plain done sentence',
        'mutation' => 'drop the catalogue\'s check of it',
        'edits' => [['src/Limits/LimitCatalog.php', 'if (! is_string($done) || ! str_ends_with($done, \'.\') || str_contains($done, \'`\')) {', 'if (! is_string($done)) {']],
        'tests' => ['tests/LimitsCatalogTest.php'],
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
