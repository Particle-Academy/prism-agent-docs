<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Check;

use LastWord\Agent;
use Prism\AgentDocs\Pictures\PictureRegistry;
use Prism\AgentDocs\Source;
use Prism\AgentDocs\Support\DocumentTree;
use Prism\AgentDocs\Support\Paths;
use Throwable;

/**
 * Re-opens the .docx the writer produced and checks it against what was asked.
 *
 * It reads the FILE, with last-word's own reader, rather than trusting the
 * document model it was written from: the bytes are what the person gets, and
 * a check of the model would pass a file the writer quietly changed.
 *
 *  1. It is not empty, unless the host said empty is allowed (scenario 1's
 *     saved result held no text and no picture).
 *  2. Every picture the draft places is in the file, in the same part, with
 *     the same bytes (scenario 1: a header logo that is not in the header).
 *  3. Every band the draft did not mention is the working copy's band, as
 *     Word would write it (owner rule: a rewrite that says nothing of the
 *     header keeps the header). The working copy is the document the host
 *     chose to revise, so a continued draft's header is the one kept.
 *  4. It changes something. A draft identical to the working copy presented
 *     as the change is scenario 3's failure.
 *  5. A reported limit's alternative is not in it. Every path that
 *     alternative would change (`page.margins.left`) holds the working copy's
 *     value, unless the request asked for that change (Moic's 0.1.0 test:
 *     the message offered zero side margins and the draft already had them).
 *     The workspace reverts such a change before writing; this is the check
 *     that the file agrees.
 *  6. It has no first-page-only band the working copy lacked, unless the
 *     request asked for first-page-only (Moic's 0.1.0 test: the letterhead
 *     art moved into `firstHeader`, so page two had no logo).
 */
final class SelfCheck
{
    /**
     * @param  array<string, mixed>  $intended  the composed draft, pictures as `image:NAME` or data URLs
     * @param  array<string, string>  $images  every registered picture, name => bytes
     * @param  list<string>  $keptBands  bands the draft did not mention, carried from the working copy
     * @param  list<string>  $unchangedPaths  paths of reported limits' alternatives, which must hold the working copy's value
     * @param  list<string>  $noNewBands  first-page bands the file may hold only if the working copy did
     * @return list<Problem>
     */
    public static function run(Source $source, array $intended, array $images, string $bytes, array $keptBands, bool $allowEmpty, array $unchangedPaths = [], array $noNewBands = []): array
    {
        try {
            $produced = Agent::read($bytes);
        } catch (Throwable $e) {
            return [new Problem(Problem::UNREADABLE, '/', 'The written file could not be read back: '.$e->getMessage())];
        }
        unset($produced['unrepresented'], $produced['sourceKind']);

        $problems = [];

        if (! $allowEmpty && ! DocumentTree::hasContent($produced)) {
            $problems[] = new Problem(Problem::EMPTY_DOCUMENT, '/', 'The file has no text and no picture anywhere: not in the body, the header or the footer. Write the content the request asks for.');
        }

        array_push($problems, ...self::pictures($intended, $images, $produced));
        array_push($problems, ...self::bands($source, $produced, $keptBands));
        array_push($problems, ...self::offeredOnly($source, $produced, $unchangedPaths));
        array_push($problems, ...self::newFirstPageBands($source, $produced, $noNewBands));

        if ($problems === [] && $source->exists() && Agent::equivalent($produced, $source->read)) {
            $problems[] = new Problem(Problem::NO_CHANGE, '/', 'This draft is identical to the document you were given, so it changes nothing. If the request cannot be done in this format, call report_limit instead of writing a draft.');
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $intended
     * @param  array<string, string>  $images
     * @param  array<string, mixed>  $produced
     * @return list<Problem>
     */
    private static function pictures(array $intended, array $images, array $produced): array
    {
        /** @var array<string, array<string, int>> $have part => sha256 => count */
        $have = [];
        foreach (DocumentTree::images($produced) as $image) {
            $data = PictureRegistry::decode($image['src']);
            if ($data !== null) {
                $hash = hash('sha256', $data);
                $have[$image['part']][$hash] = ($have[$image['part']][$hash] ?? 0) + 1;
            }
        }

        $problems = [];
        foreach (DocumentTree::images($intended) as $image) {
            $src = $image['src'];
            $name = str_starts_with($src, 'image:') ? substr($src, 6) : null;
            $data = $name !== null ? ($images[$name] ?? null) : PictureRegistry::decode($src);
            if ($data === null) {
                $problems[] = new Problem(Problem::PICTURE_MISSING, $image['path'], sprintf('The picture %s is not one the host registered, so it cannot be placed.', $name !== null ? 'image:'.$name : 'at this path'));

                continue;
            }
            $hash = hash('sha256', $data);
            if (($have[$image['part']][$hash] ?? 0) > 0) {
                $have[$image['part']][$hash]--;

                continue;
            }
            $problems[] = new Problem(Problem::PICTURE_MISSING, $image['path'], sprintf('The picture %s was placed in %s but the written file does not hold it there, byte for byte.', $name !== null ? 'image:'.$name : 'at this path', $image['part']));
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $produced
     * @param  list<string>  $keptBands
     * @return list<Problem>
     */
    private static function bands(Source $source, array $produced, array $keptBands): array
    {
        $base = is_array($source->read['page'] ?? null) ? $source->read['page'] : [];
        $now = is_array($produced['page'] ?? null) ? $produced['page'] : [];

        $problems = [];
        foreach ($keptBands as $band) {
            if (! isset($base[$band])) {
                continue;
            }
            if (! isset($now[$band])) {
                $problems[] = new Problem(Problem::BAND_CHANGED, '/page/'.$band, sprintf('The document you were given has a %s and the draft lost it. Leave page.%s out of the draft to keep it, or list it in remove only if the request asks for that.', $band, $band));

                continue;
            }
            if (! Agent::equivalent(['blocks' => [], 'page' => [$band => $base[$band]]], ['blocks' => [], 'page' => [$band => $now[$band]]])) {
                $problems[] = new Problem(Problem::BAND_CHANGED, '/page/'.$band, sprintf('The request did not change the %s, but the written file has a different one. Leave page.%s out of the draft to keep it.', $band, $band));
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $produced
     * @param  list<string>  $paths
     * @return list<Problem>
     */
    private static function offeredOnly(Source $source, array $produced, array $paths): array
    {
        $problems = [];
        foreach ($paths as $path) {
            if (! Paths::same($source->read, $produced, $path)) {
                $problems[] = new Problem(Problem::ALTERNATIVE_APPLIED, '/'.str_replace('.', '/', $path), sprintf('%s is part of the alternative to a limit you reported. An alternative is offered to the person, never applied: leave %s as it is in the document you were given.', $path, $path));
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $produced
     * @param  list<string>  $bands
     * @return list<Problem>
     */
    private static function newFirstPageBands(Source $source, array $produced, array $bands): array
    {
        $base = is_array($source->read['page'] ?? null) ? $source->read['page'] : [];
        $now = is_array($produced['page'] ?? null) ? $produced['page'] : [];

        $problems = [];
        foreach ($bands as $band) {
            if (isset($now[$band]) && ! isset($base[$band])) {
                $problems[] = new Problem(Problem::FIRST_PAGE_BAND, '/page/'.$band, sprintf('page.%s shows on the first page only, and the request did not ask for that. Use the band every page shows (page.%s) instead.', $band, lcfirst(substr($band, 5))));
            }
        }

        return $problems;
    }
}
