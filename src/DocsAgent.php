<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use Illuminate\Database\Eloquent\Model;
use LastWord\Exceptions\ImageRefused;
use LogicException;
use Prism\AgentDocs\Limits\LimitCatalog;
use Prism\AgentDocs\Revisions\Inspection;
use Prism\AgentDocs\Revisions\LastWordTrackedChanges;
use Prism\AgentDocs\Revisions\TrackedChangesGuard;
use Prism\AgentDocs\Tools\DocsTools;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Throwable;

/**
 * The Word-document subagent, as a named prism-harness agent.
 *
 * It is a harness agent like any other: a mode (`edit`) with a system prompt
 * and a tool list, in its own agent namespace (`docs` by default), so it
 * inherits no host tools and the host's agents inherit none of its. The one
 * thing a plain mode cannot hold is per-edit state, the document, so edit()
 * opens a Workspace for the session it runs on and the tool factories find it
 * by the session's key. A tool resolved for a session with no open edit
 * refuses: there is nothing to write to.
 *
 * Phase B seam: edit() is the whole contract, a request and a store in, a
 * DocsResult out, with no harness type in either. An ACP AgentDriver, or a
 * harness `subagents` declaration, wraps that call; neither needs to reach
 * past it.
 */
final class DocsAgent
{
    public const string AGENT = 'docs';

    public const string MODE = 'edit';

    public const string OWNER = 'particle-academy/prism-agent-docs';

    /** How many times a run that skipped a doable part is returned to the model. Each is one more model call. */
    public const int PUSHBACKS = 1;

    /** @var array<string, Workspace> session key => the edit running on it */
    private array $open = [];

    private bool $installed = false;

    private readonly LimitCatalog $catalog;

    private readonly TrackedChangesGuard $trackedChanges;

    public function __construct(
        private readonly PrismHarness $harness,
        private readonly ToolRegistry $tools,
        private readonly ModeRegistry $modes,
        ?LimitCatalog $catalog = null,
        private readonly string $agent = self::AGENT,
        private readonly int $maxSteps = 8,
        ?TrackedChangesGuard $trackedChanges = null,
    ) {
        $this->catalog = $catalog ?? LimitCatalog::load();
        $this->trackedChanges = $trackedChanges ?? new LastWordTrackedChanges;
    }

    /** Register the mode and the tools in this agent's namespace. Safe to call more than once. */
    public function install(): self
    {
        if ($this->installed) {
            return $this;
        }

        $tools = $this->tools->forAgent($this->agent);
        foreach (DocsTools::ALL as $name) {
            $tools->registerFactory($name, fn (Session $session): Tool => $this->toolFor($name, $session), self::OWNER);
        }
        $this->modes->forAgent($this->agent)
            ->register(self::MODE, [
                'system_prompt' => Prompt::system($this->catalog),
                'tools' => DocsTools::ALL,
                'skills' => [],
                'max_steps' => $this->maxSteps,
            ], self::OWNER)
            ->setDefault(self::MODE);

        $this->installed = true;

        return $this;
    }

    /**
     * Revise the document the host chose (or create one) as the request asks.
     *
     * `$document` is either the HOST's working copy, used verbatim, or a
     * DocumentStore, whose working copy is WorkingCopy::fromStore()'s: the
     * pending draft when it holds something, otherwise the saved file.
     *
     * With a store, a draft that passed its checks is written to the draft
     * slot, replacing whatever was there. With a working copy, nothing is
     * written anywhere: the draft is in the result for the host to keep. The
     * saved file is never written, either way, and the result's baseVersion is
     * the working copy's, for the host to check before it saves.
     */
    public function edit(Model|ParticipantRef $participant, WorkingCopy|DocumentStore $document, string $request, DocsOptions $options = new DocsOptions, ?string $scope = null): DocsResult
    {
        $this->install();

        $version = $document instanceof WorkingCopy ? $document->baseVersion : WorkingCopy::versionOf($document->saved());

        if (strlen($request) > $options->maxRequestBytes) {
            return DocsResult::failed(DocsResult::REQUEST_TOO_LONG, $version);
        }

        try {
            $copy = $document instanceof WorkingCopy ? $document : WorkingCopy::fromStore($document, $this->trackedChanges);

            // Before anything reads it: last-word's reader silently accepts
            // tracked changes, so a draft built from that read would lose
            // every deletion a reviewer proposed and make every insertion
            // final. Refused in fixed words, with no model call.
            if ($copy->bytes !== null) {
                $inspection = $this->trackedChanges->inspect($copy->bytes);
                if ($inspection->refuses()) {
                    return DocsResult::refused($inspection->status === Inspection::TRACKED ? Refusal::TrackedChanges : Refusal::Uncheckable, $version);
                }
            }

            $source = Source::fromWorkingCopy($copy);
        } catch (Throwable $e) {
            // The reader's words stay with the host: an exception's text is
            // neither written for the person nor safe to show them.
            report($e);

            return DocsResult::failed(DocsResult::UNREADABLE, $version);
        }

        $workspace = new Workspace($source, $this->catalog, $options, $request);
        if ($workspace->pictureCount() > $options->maxImages) {
            return DocsResult::failed(DocsResult::TOO_MANY_PICTURES, $version);
        }
        try {
            $workspace->assertWritable();
        } catch (ImageRefused $e) {
            return DocsResult::failed(sprintf('A picture in the document is one the writer refuses (%s), so it could not be kept.', $e->reason), $version);
        }

        $session = $this->harness->forAgent($this->agent)->session($participant, $scope);
        $key = $session->key();
        if (isset($this->open[$key])) {
            throw new LogicException('An edit is already running on this session; give each concurrent edit its own scope.');
        }

        $this->open[$key] = $workspace;
        try {
            $session->usingMode(self::MODE);
            if ($options->freshConversation) {
                $session->newConversation();
            }
            $session->send(Prompt::opening($request, $workspace));

            // The run ended, but a reported limit's doable part, which the
            // request asked for, is not in what it would hand over (G-316 lab
            // run 01a2002d: limit reported, no draft written). Return it to
            // the model, a bounded number of times, before composing a result.
            for ($i = 0; $i < self::PUSHBACKS; $i++) {
                $skipped = $workspace->skippedParts();
                if ($skipped === []) {
                    break;
                }
                $session->send(Prompt::pushback($skipped));
            }
        } finally {
            unset($this->open[$key]);
        }

        $result = DocsResult::from($workspace);
        if ($result->draft !== null && $document instanceof DocumentStore) {
            $document->putDraft($result->draft);
        }

        return $result;
    }

    /**
     * The agent as one tool a parent agent can call.
     *
     * It takes a store, not a working copy: each call resolves the store's
     * working copy afresh and writes its draft back, so a second request
     * continues the first one's draft.
     *
     * What comes back to the parent is the composed outcome and message, never
     * the child model's text, so nothing the child read (a hostile document,
     * say) reaches the parent as instructions.
     */
    public function tool(Model|ParticipantRef $participant, DocumentStore $store, DocsOptions $options = new DocsOptions, ?string $scope = null): Tool
    {
        return (new Tool)
            ->as('edit_document')
            ->for('Ask the Word-document specialist to edit the open document, or create it. Describe the change in plain words.')
            ->withStringParameter('request', 'What to change, in the person\'s words.')
            ->using(function (string $request) use ($participant, $store, $options, $scope): string {
                $result = $this->edit($participant, $store, $request, $options, $scope);

                return json_encode(['outcome' => $result->outcome->value, 'message' => $result->message, 'limits' => $result->limitIds(), 'refusal' => $result->refusal?->value], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            });
    }

    public function catalog(): LimitCatalog
    {
        return $this->catalog;
    }

    private function toolFor(string $name, Session $session): Tool
    {
        $workspace = $this->open[$session->key()] ?? null;
        if ($workspace === null) {
            return (new Tool)
                ->as($name)
                ->for('Unavailable: no document is open on this session.')
                ->using(static fn (mixed ...$arguments): string => '{"ok":false,"problems":[{"code":"no_document","where":"/","message":"No document is open on this session."}]}');
        }

        return match ($name) {
            DocsTools::WRITE_DRAFT => DocsTools::writeDraft($workspace),
            DocsTools::REPORT_LIMIT => DocsTools::reportLimit($workspace),
            default => DocsTools::documentSchema($workspace),
        };
    }
}
