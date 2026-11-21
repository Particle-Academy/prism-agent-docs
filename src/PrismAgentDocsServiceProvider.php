<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prism\AgentDocs\Revisions\LastWordTrackedChanges;
use Prism\AgentDocs\Revisions\TrackedChangesGuard;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;

/**
 * Binds one DocsAgent per application. Nothing is registered with the harness
 * until the agent is first used (DocsAgent::install() runs on the first edit),
 * so installing the package changes no existing agent.
 *
 * TrackedChangesGuard is bound only if the host has not bound it: the seam
 * where a host replaces the default (last-word's own signal where installed, plus the archive scan).
 */
final class PrismAgentDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(TrackedChangesGuard::class, LastWordTrackedChanges::class);

        $this->app->singleton(DocsAgent::class, fn (Application $app): DocsAgent => new DocsAgent(
            $app->make(PrismHarness::class),
            $app->make(ToolRegistry::class),
            $app->make(ModeRegistry::class),
            trackedChanges: $app->make(TrackedChangesGuard::class),
        ));
    }
}
