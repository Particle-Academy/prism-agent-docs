<?php

declare(strict_types=1);

namespace Prism\AgentDocs\Support;

/**
 * Whether a request EXPLICITLY asks for something. Deliberately narrow.
 *
 * Two rules depend on it: a limit's alternative is applied only when the
 * request asked for that exact change, and a first-page-only band is kept only
 * when the request asked for first-page-only. Getting this wrong in the
 * generous direction is the defect being fixed (a draft that does what the
 * message only offers), so every doubt resolves to "not asked":
 *
 *  - A phrase counts only as a whole phrase from a fixed list (the catalogue's
 *    `askedBy`, or FIRST_PAGE_ONLY below), matched case-insensitively with
 *    whitespace collapsed and on word boundaries. "No margins for the header"
 *    does not ask for zero side margins for the whole page, however close the
 *    meaning: the model's reading of intent is exactly what is not trusted.
 *  - The digit 0 reads as "zero", also at the end of a sentence ("0."), but
 *    not inside another number ("0.5", "10"); typographic apostrophes read as
 *    plain ones. So "set the side margins to 0 for the whole page" matches.
 *  - A match with a negation in the four words before it does not count:
 *    "don't set the side margins to zero for the whole page".
 */
final class Asked
{
    /** Phrases that ask for a first-page-only band. */
    public const array FIRST_PAGE_ONLY = [
        'first page only',
        'first-page only',
        'first-page-only',
        'only on the first page',
        'only the first page',
        'only on page one',
        'only on page 1',
        'page one only',
        'page 1 only',
        'different first page',
        'first-page header',
        'first page header',
        'first-page footer',
        'first page footer',
    ];

    private const array NEGATIONS = ['not', "don't", 'dont', 'never', 'without', 'instead', 'rather', "doesn't", "shouldn't", "won't"];

    /** @param list<string> $phrases */
    public static function forAny(string $request, array $phrases): bool
    {
        $text = self::normalise($request);
        foreach ($phrases as $phrase) {
            $needle = self::normalise($phrase);
            if ($needle === '') {
                continue;
            }
            $offset = 0;
            while (preg_match('/(?<![\w-])'.preg_quote($needle, '/').'(?![\w-])/u', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $at = $match[0][1];
                if (! self::negated(substr($text, 0, $at))) {
                    return true;
                }
                $offset = $at + strlen($needle);
            }
        }

        return false;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(str_replace(["\u{2019}", "\u{2018}"], "'", $text));
        // A full stop after the 0 ends the sentence ("set it to 0."); only a
        // digit after the point makes it another number ("0.5").
        $text = (string) preg_replace('/(?<![\w.])0(?!\w|\.\d)/u', 'zero', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function negated(string $before): bool
    {
        $words = preg_split('/[^\w\']+/u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect(array_slice($words, -4), self::NEGATIONS) !== [];
    }
}
