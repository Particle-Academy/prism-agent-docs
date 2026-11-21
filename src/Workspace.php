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
use Prism\AgentDocs\Support\DocumentTree;

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

    private ?string $draft = null;

    /** @var list<array{limit: Limit, alternative: int}> */
    private array $limits = [];

    /** @var list<Problem> */
    private array $lastProblems = [];

    private int $attempts = 0;

    public function __construct(
        public readonly Source $source,
        public readonly LimitCatalog $catalog,
        public readonly DocsOptions $options = new DocsOptions,
    ) {
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
     * @param  list<string>  $remove
     * @return array{ok: bool, problems?: list<array{code: string, where: string, message: string}>, note?: string}
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
        foreach ($remove as $band) {
            if (! in_array($band, DocumentTree::BANDS, true)) {
                return $this->refuse([new Problem(Problem::INVALID, '/remove', sprintf('"%s" is not a band; remove takes %s.', $band, implode(', ', DocumentTree::BANDS)))]);
            }
            if (isset($document['page']) && is_array($document['page']) && array_key_exists($band, $document['page'])) {
                return $this->refuse([new Problem(Problem::INVALID, '/page/'.$band, sprintf('page.%s is both written and removed; do one.', $band))]);
            }
        }

        [$composed, $kept] = $this->compose($document, $remove);

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

        $problems = SelfCheck::run($this->source, $composed, $this->imageBytes, $bytes, $kept, $this->options->allowEmpty);
        if ($problems !== []) {
            return $this->refuse($problems);
        }

        $this->draft = $bytes;
        $this->lastProblems = [];

        return ['ok' => true, 'note' => 'The draft passed its checks and will be handed to the person. Do not describe it; end your turn with one short line.'];
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

        return ['ok' => true, 'note' => 'Recorded; the person will be told the limit and the alternative. Do not write a draft that changes nothing. End your turn with one short line.'];
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
     * @return array{ok: false, problems: list<array{code: string, where: string, message: string}>}
     */
    private function refuse(array $problems): array
    {
        // The last write is the one the model meant. An earlier draft that
        // passed is not handed over in its place: that would be saving
        // something other than what the run ended on, without saying so.
        $this->draft = null;
        $this->lastProblems = $problems;

        return ['ok' => false, 'problems' => array_map(static fn (Problem $p): array => $p->toArray(), $problems)];
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
