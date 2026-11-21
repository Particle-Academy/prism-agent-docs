<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use InvalidArgumentException;
use LastWord\Agent;
use LastWord\Exceptions\ImageRefused;
use LastWord\Exceptions\SchemaException;
use Prism\AgentDocs\Check\Problem;
use Prism\AgentDocs\Check\SelfCheck;
use Prism\AgentDocs\Limits\Limit;
use Prism\AgentDocs\Limits\LimitCatalog;
use Prism\AgentDocs\Pictures\PictureRegistry;
use Prism\AgentDocs\Support\Asked;
use Prism\AgentDocs\Support\DocumentTree;
use Prism\AgentDocs\Support\Paths;

/**
 * One edit's state: the working copy it starts from, the pictures it may
 * place, the draft that passed its checks, and any limit it reported.
 *
 * The tools the model calls are thin wrappers over writeDraft() and
 * reportLimit(), so every rule the agent holds is here, in code a test can
 * reach without a model.
 */
final class Workspace
{
    /** Top-level keys a draft keeps from the working copy when it leaves them out. */
    private const array CARRIED = ['title', 'meta', 'blocks'];

    /** Page keys a draft keeps from the working copy when it leaves them out (margins merge side by side). */
    private const array CARRIED_PAGE = ['size', 'orientation', 'background', 'border'];

    /** @var array<string, mixed> name => what last-word's `images` option takes */
    private readonly array $writerImages;

    /** @var array<string, string> name => bytes, for the self-check */
    private readonly array $imageBytes;

    /** First-page-only band => the band every page shows. */
    private const array FIRST_PAGE_BANDS = ['firstHeader' => 'header', 'firstFooter' => 'footer'];

    private ?string $draft = null;

    /** @var array<string, mixed>|null the composed document the passing draft was written from */
    private ?array $drafted = null;

    /** @var array{0: array<string, mixed>, 1: list<string>}|null what the model sent for the passing draft, to re-run when a limit is reported after it */
    private ?array $accepted = null;

    /** @var list<array{limit: Limit, alternative: int}> */
    private array $limits = [];

    /** @var list<Problem> */
    private array $lastProblems = [];

    private int $attempts = 0;

    /** @var list<string> alternative paths the request itself asks to change */
    private readonly array $askedPaths;

    private readonly bool $askedFirstPageOnly;

    /**
     * @param  string  $request  the person's request, read only for what it EXPLICITLY asks (Support\Asked)
     */
    public function __construct(
        public readonly Source $source,
        public readonly LimitCatalog $catalog,
        public readonly DocsOptions $options = new DocsOptions,
        public readonly string $request = '',
    ) {
        $asked = [];
        foreach ($catalog->all() as $limit) {
            if ($limit->alternativePaths !== [] && Asked::forAny($request, $limit->askedBy)) {
                array_push($asked, ...$limit->alternativePaths);
            }
        }
        $this->askedPaths = array_values(array_unique($asked));
        $this->askedFirstPageOnly = Asked::forAny($request, Asked::FIRST_PAGE_ONLY);

        $writer = $source->images;
        $bytes = $source->images;
        foreach ($options->images as $name => $image) {
            if (isset($writer[$name]) || str_starts_with($name, PictureRegistry::PREFIX)) {
                throw new InvalidArgumentException(sprintf('The host image name "%s" is taken: names starting "%s" are the working copy\'s own pictures.', $name, PictureRegistry::PREFIX));
            }
            $writer[$name] = $image;
            $bytes[$name] = self::bytesOf($image) ?? throw new InvalidArgumentException(sprintf('The host image "%s" is not PNG or JPEG bytes, a data URL, or {data: ...}.', $name));
        }
        $this->writerImages = $writer;
        $this->imageBytes = $bytes;
    }

    /**
     * The options last-word writes with: every picture by name, and the
     * host's limits. `maxImages` is the host's configured cap, never the
     * number of pictures the file happens to hold: a file is not allowed to
     * raise its own limit.
     *
     * @return array{images: array<string, mixed>, imageLimits: array<string, int>}
     */
    public function writerOptions(): array
    {
        return [
            'images' => $this->writerImages,
            'imageLimits' => array_replace($this->options->imageLimits, ['maxImages' => $this->options->maxImages]),
        ];
    }

    /** How many distinct pictures this edit holds: the working copy's own and the host's. */
    public function pictureCount(): int
    {
        return count($this->writerImages);
    }

    /**
     * Refuses before any model call when the working copy's own pictures
     * cannot be registered (a picture over last-word's size limit, say).
     * Failing here costs nothing; failing on the first write costs a turn and
     * tells the model about a problem it cannot fix.
     *
     * @throws ImageRefused
     */
    public function assertWritable(): void
    {
        Agent::validate(['blocks' => []], $this->writerOptions());
    }

    /**
     * Write a draft, check it, and keep it if it passes.
     *
     * Every top-level key and page key the draft leaves out is the working
     * copy's: the body, the title, each band, each margin. So a request about
     * the header can send only `page.header`, and a whole rewrite that says
     * nothing of the header keeps it. `remove` names bands to drop on purpose.
     *
     * Two of the model's choices are overruled here, in code, because a
     * prompt asking for them was not enough (Moic's live test of 0.1.0):
     *
     *  - An alternative to a reported limit is OFFERED, never applied. Every
     *    path it would change is set back to the working copy's value before
     *    the draft is written, unless the request asked for that change.
     *  - A first-page-only band (`firstHeader`, `firstFooter`) the working
     *    copy did not have, and the request did not ask for, becomes the band
     *    every page shows. One with nothing in it is dropped.
     *
     * @param  list<string>  $remove
     * @return array{ok: bool, problems?: list<array{code: string, where: string, message: string}>, note?: string, changed?: list<string>}
     */
    public function writeDraft(mixed $document, array $remove = []): array
    {
        $this->attempts++;
        if (is_string($document)) {
            $decoded = json_decode($document, true);
            $document = is_array($decoded) ? $decoded : null;
        }
        $document = is_array($document) ? self::object($document) : null;
        if ($document === null) {
            return $this->refuse([new Problem(Problem::INVALID, '/', 'document must be a last-word document object, {"blocks": [...], ...}, not a list or a scalar.')]);
        }

        return $this->attempt($document, $remove);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $remove
     * @return array{ok: bool, problems?: list<array{code: string, where: string, message: string}>, note?: string, changed?: list<string>}
     */
    private function attempt(array $document, array $remove): array
    {
        $sent = [$document, $remove];
        $changed = [];

        $basePage = is_array($this->source->named['page'] ?? null) ? $this->source->named['page'] : [];
        if (! $this->askedFirstPageOnly && is_array($document['page'] ?? null)) {
            $page = $document['page'];
            foreach (self::FIRST_PAGE_BANDS as $first => $every) {
                if (! array_key_exists($first, $page) || array_key_exists($first, $basePage)) {
                    continue;
                }
                if (! is_array($page[$first]) || ! DocumentTree::hasContent($page[$first])) {
                    unset($page[$first]);
                    $changed[] = sprintf('page.%s was dropped: it shows on the first page only, and the request did not ask for that.', $first);

                    continue;
                }
                if (array_key_exists($every, $page)) {
                    return $this->refuse([new Problem(Problem::FIRST_PAGE_BAND, '/page/'.$first, sprintf('page.%s shows on the first page only, and the request did not ask for that, so page two onwards would not show it. Put its content in page.%s, which every page shows, and leave page.%s out.', $first, $every, $first))]);
                }
                $page[$every] = $page[$first];
                unset($page[$first]);
                $changed[] = sprintf('page.%s was written as page.%s: it showed on the first page only, and the request did not ask for that.', $first, $every);
            }
            $document['page'] = $page;
        }

        foreach ($remove as $band) {
            if (! in_array($band, DocumentTree::BANDS, true)) {
                return $this->refuse([new Problem(Problem::INVALID, '/remove', sprintf('"%s" is not a band; remove takes %s.', $band, implode(', ', DocumentTree::BANDS)))]);
            }
            if (isset($document['page']) && is_array($document['page']) && array_key_exists($band, $document['page'])) {
                return $this->refuse([new Problem(Problem::INVALID, '/page/'.$band, sprintf('page.%s is both written and removed; do one.', $band))]);
            }
        }

        [$composed, $kept] = $this->compose($document, $remove);

        foreach ($this->offeredPaths() as $path) {
            if (! Paths::same($composed, $this->source->named, $path)) {
                $composed = Paths::copy($composed, $this->source->named, $path);
                $changed[] = sprintf('%s was set back to the document\'s own value: it is part of the alternative to a limit you reported, which is offered to the person, not applied.', $path);
            }
        }

        $repaired = Agent::validateAndRepair($composed, $this->writerOptions());
        if (! $repaired['ok']) {
            $problems = [];
            foreach ($repaired['errors'] as $error) {
                $problems[] = new Problem($error['code'] ?? Problem::INVALID, $error['path'], $error['message']);
            }

            return $this->refuse($problems);
        }

        try {
            $bytes = Agent::toBytes($repaired['schema'], $this->writerOptions());
        } catch (SchemaException|ImageRefused $e) {
            return $this->refuse([new Problem(Problem::INVALID, '/', $e->getMessage())]);
        }

        $problems = SelfCheck::run($this->source, $composed, $this->imageBytes, $bytes, $kept, $this->options->allowEmpty, $this->offeredPaths(), $this->askedFirstPageOnly ? [] : array_keys(self::FIRST_PAGE_BANDS));
        if ($problems !== []) {
            return $this->refuse($problems, $changed);
        }

        $this->draft = $bytes;
        $this->drafted = $composed;
        $this->accepted = $sent;
        $this->lastProblems = [];

        $result = ['ok' => true, 'note' => 'The draft passed its checks and will be handed to the person. Do not describe it; end your turn with one short line.'];
        if ($changed !== []) {
            $result['changed'] = $changed;
        }

        return $result;
    }

    /**
     * The paths no draft may change: those of every reported limit's
     * alternatives, less those the request itself asks to change.
     *
     * @return list<string>
     */
    public function offeredPaths(): array
    {
        $paths = [];
        foreach ($this->limits as $reported) {
            foreach ($reported['limit']->alternativePaths as $path) {
                if (! in_array($path, $this->askedPaths, true)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Whether the draft handed over makes a reported limit's alternative
     * change: only ever because the request asked for it, since offeredPaths()
     * keeps every other draft from doing so. The person is then told it was
     * done, not offered.
     */
    public function alternativeApplied(Limit $limit): bool
    {
        if ($this->draft === null || $this->drafted === null) {
            return false;
        }
        $drafted = $this->drafted;

        return array_any($limit->alternativePaths, fn (string $path): bool => in_array($path, $this->askedPaths, true) && ! Paths::same($drafted, $this->source->named, $path));
    }

    /**
     * The first-page-only bands the draft handed over has, with something in
     * them. Only ever the working copy's own, or ones the request asked for.
     *
     * @return list<string>
     */
    public function firstPageBands(): array
    {
        if ($this->draft === null || $this->drafted === null) {
            return [];
        }
        $page = is_array($this->drafted['page'] ?? null) ? $this->drafted['page'] : [];
        $bands = [];
        foreach (array_keys(self::FIRST_PAGE_BANDS) as $band) {
            if (is_array($page[$band] ?? null) && DocumentTree::hasContent($page[$band])) {
                $bands[] = $band;
            }
        }

        return $bands;
    }

    /**
     * Record that the request needs something the format cannot do.
     *
     * Only an id from the catalog is accepted, and the person is told the
     * catalog's statement and alternative, not the model's paraphrase of it.
     *
     * @return array{ok: bool, problems?: list<array{code: string, where: string, message: string}>, note?: string}
     */
    public function reportLimit(string $id, int $alternative = 0): array
    {
        if (! $this->catalog->has($id)) {
            return ['ok' => false, 'problems' => [new Problem(Problem::INVALID, '/limit', sprintf('"%s" is not a known limit; use one of: %s.', $id, implode(', ', $this->catalog->ids())))->toArray()]];
        }
        $limit = $this->catalog->get($id);
        if ($alternative < 0 || $alternative >= count($limit->alternatives)) {
            $alternative = 0;
        }
        foreach ($this->limits as $reported) {
            if ($reported['limit']->id === $id) {
                return ['ok' => true, 'note' => 'Already recorded.'];
            }
        }
        $this->limits[] = ['limit' => $limit, 'alternative' => $alternative];

        $note = 'Recorded; the person will be told the limit and offered the alternative. The alternative is not applied: do not put it in a draft. Do not write a draft that changes nothing. End your turn with one short line.';

        // A draft that passed before this limit was reported may already make
        // the alternative's change. Write it again under the new limit, so the
        // draft handed over never holds what the message only offers.
        if ($this->accepted !== null && $this->drafted !== null) {
            foreach ($this->offeredPaths() as $path) {
                if (! Paths::same($this->drafted, $this->source->named, $path)) {
                    $again = $this->attempt(...$this->accepted);
                    $note = $again['ok']
                        ? 'Recorded. Your draft made the alternative\'s change, which is offered to the person, not applied; it was taken out and the draft written again. End your turn with one short line.'
                        : 'Recorded. Your draft made the alternative\'s change, which is offered to the person, not applied; without it the draft changed nothing, so there is no draft. End your turn with one short line.';

                    break;
                }
            }
        }

        return ['ok' => true, 'note' => $note];
    }

    public function draft(): ?string
    {
        return $this->draft;
    }

    /** @return list<array{limit: Limit, alternative: int}> */
    public function limits(): array
    {
        return $this->limits;
    }

    /** @return list<Problem> */
    public function lastProblems(): array
    {
        return $this->lastProblems;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $remove
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function compose(array $document, array $remove = []): array
    {
        $base = $this->source->named;
        foreach (self::CARRIED as $key) {
            if (! array_key_exists($key, $document) && array_key_exists($key, $base)) {
                $document[$key] = $base[$key];
            }
        }

        $basePage = is_array($base['page'] ?? null) ? $base['page'] : [];
        $page = is_array($document['page'] ?? null) ? $document['page'] : [];
        $kept = [];
        foreach (DocumentTree::BANDS as $band) {
            if (in_array($band, $remove, true)) {
                unset($page[$band]);

                continue;
            }
            if (! array_key_exists($band, $page) && array_key_exists($band, $basePage)) {
                $page[$band] = $basePage[$band];
                $kept[] = $band;
            }
        }
        foreach (self::CARRIED_PAGE as $key) {
            if (! array_key_exists($key, $page) && array_key_exists($key, $basePage)) {
                $page[$key] = $basePage[$key];
            }
        }
        $margins = array_replace(
            is_array($basePage['margins'] ?? null) ? $basePage['margins'] : [],
            is_array($page['margins'] ?? null) ? $page['margins'] : [],
        );
        if ($margins !== []) {
            $page['margins'] = $margins;
        }

        if ($page !== []) {
            $document['page'] = $page;
        } else {
            unset($document['page']);
        }
        $document['blocks'] ??= [];

        return [$document, $kept];
    }

    /**
     * @param  list<Problem>  $problems
     * @param  list<string>  $changed
     * @return array{ok: false, problems: list<array{code: string, where: string, message: string}>, changed?: list<string>}
     */
    private function refuse(array $problems, array $changed = []): array
    {
        // The last write is the one the model meant. An earlier draft that
        // passed is not handed over in its place: that would be saving
        // something other than what the run ended on, without saying so.
        $this->draft = null;
        $this->drafted = null;
        $this->accepted = null;
        $this->lastProblems = $problems;

        $result = ['ok' => false, 'problems' => array_map(static fn (Problem $p): array => $p->toArray(), $problems)];
        if ($changed !== []) {
            $result['changed'] = $changed;
        }

        return $result;
    }

    /**
     * A document object as the model sent it: string keys only. A JSON list
     * (or a list nested as the document) is not a document.
     *
     * @param  array<mixed>  $value
     * @return array<string, mixed>|null
     */
    private static function object(array $value): ?array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    private static function bytesOf(mixed $image): ?string
    {
        if (is_array($image)) {
            $image = $image['data'] ?? null;
        }
        if (! is_string($image) || $image === '') {
            return null;
        }
        if (str_starts_with($image, 'data:')) {
            return PictureRegistry::decode($image);
        }

        return str_starts_with($image, "\x89PNG") || str_starts_with($image, "\xFF\xD8") ? $image : null;
    }
}
