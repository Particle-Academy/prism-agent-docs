<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Limits\Limit;

/**
 * What an edit hands back.
 *
 * `message` is what the person reads, and it is COMPOSED HERE, not taken from
 * the model. The owner's rule: say the draft is ready, and say more only when
 * something asked for could not be done; no narration of what was built. A
 * prompt asking the model for that is a request; building the message from
 * the outcome is a guarantee. The model's own closing text is not returned.
 *
 * `baseVersion` is the working copy's, handed back unchanged. Before saving a
 * draft the HOST must check that the saved file is still that version and
 * refuse (or re-run the edit) if it has moved on; the agent never saves, so
 * it cannot make that check for you.
 */
final readonly class DocsResult
{
    public const string READY = 'The draft is ready.';

    /** Fixed wording: what the reader said is reported to the host, never shown. */
    public const string UNREADABLE = 'The document could not be opened, so nothing was changed.';

    public const string TOO_MANY_PICTURES = 'The document holds more pictures than this editor accepts, so nothing was changed.';

    public const string REQUEST_TOO_LONG = 'The request is too long for this editor. Shorten it and try again.';

    /**
     * @param  list<array{limit: Limit, alternative: int}>  $limits
     * @param  list<Problem>  $problems
     */
    public function __construct(
        public Outcome $outcome,
        public string $message,
        public string $baseVersion,
        public ?string $draft = null,
        public array $limits = [],
        public array $problems = [],
    ) {}

    /**
     * Introduces an alternative the person may ask for. The draft never
     * contains it (Workspace reverts it), so the words must not read as done.
     */
    public const string OFFER = ' The closest alternative, not applied unless you ask for it: ';

    /** Added when the draft keeps a first-page-only band, so nobody is surprised that page two differs. */
    public const array FIRST_PAGE_ONLY = [
        'firstHeader' => 'The header art appears on the first page only.',
        'firstFooter' => 'The first-page footer appears on the first page only.',
        'both' => 'The header art and the first-page footer appear on the first page only.',
    ];

    public static function from(Workspace $workspace): self
    {
        // The owner's rule: the message says what the draft does. An
        // alternative reads as an offer unless the draft really makes it,
        // which only happens when the request asked for exactly that.
        $limits = $workspace->limits();
        $notes = array_map(
            static fn (array $reported): string => $workspace->alternativeApplied($reported['limit'])
                ? $reported['limit']->statement.' '.$reported['limit']->applied
                : $reported['limit']->statement.self::OFFER.lcfirst($reported['limit']->alternatives[$reported['alternative']]),
            $limits,
        );

        $version = $workspace->source->baseVersion;
        $draft = $workspace->draft();
        if ($draft !== null) {
            $first = $workspace->firstPageBands();
            if ($first !== []) {
                $notes[] = self::FIRST_PAGE_ONLY[count($first) > 1 ? 'both' : $first[0]];
            }

            return new self(Outcome::Ready, implode(' ', [self::READY, ...$notes]), $version, $draft, $limits);
        }
        if ($limits !== []) {
            return new self(Outcome::Limit, implode(' ', $notes), $version, null, $limits);
        }
        $problems = $workspace->lastProblems();
        if ($problems !== []) {
            return self::failed('The draft could not be made: '.self::plain($problems[0]), $version, $problems);
        }

        return new self(Outcome::NoChange, 'No draft was made: the request did not lead to a change.', $version);
    }

    /** @param list<Problem> $problems */
    public static function failed(string $message, string $baseVersion, array $problems = []): self
    {
        return new self(Outcome::Failed, $message, $baseVersion, null, [], $problems);
    }

    public function ready(): bool
    {
        return $this->outcome === Outcome::Ready;
    }

    /** The person's words for a problem: never a JSON path, never a model instruction. */
    private static function plain(Problem $problem): string
    {
        return match ($problem->code) {
            Problem::EMPTY_DOCUMENT => 'it came out empty.',
            Problem::PICTURE_MISSING, 'image_unknown' => 'a picture it needed could not be placed.',
            Problem::BAND_CHANGED => 'it would have changed the header or footer, which the request did not ask for.',
            Problem::NO_CHANGE => 'it would not have changed anything.',
            Problem::ALTERNATIVE_APPLIED => 'it would have made a change that was only to be offered.',
            Problem::FIRST_PAGE_BAND => 'it would have put the header or footer on the first page only.',
            Problem::UNREADABLE => 'the written file could not be read back.',
            default => 'the document did not pass the writer\'s checks.',
        };
    }
}
