<?php

declare(strict_types=1);

namespace Prism\AgentDocs;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;

/**
 * Binds one DocsAgent per application. Nothing is registered with the harness
 * until the agent is first used (DocsAgent::install() runs on the first edit),
 * so installing the package changes no existing agent.
 */
final class PrismAgentDocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocsAgent::class, fn (Application $app): DocsAgent => new DocsAgent(
            $app->make(PrismHarness::class),
            $app->make(ToolRegistry::class),
            $app->make(ModeRegistry::class),
        ));
    }
}
