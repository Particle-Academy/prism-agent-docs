<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\AgentDocs\PrismAgentDocsServiceProvider;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No network. The only call this agent makes is the model's, and in
        // these tests the model is a script; any HTTP through Laravel's client
        // is a defect and fails the test.
        Http::preventStrayRequests();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            PrismHarnessServiceProvider::class,
            PrismAgentDocsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('prism-harness.stores', [
            'ephemeral' => 'database',
            'durable' => 'database',
        ]);
        // A provider and model name only; the ScriptedModel answers every call.
        $app['config']->set('prism-harness.agent.provider', 'anthropic');
        $app['config']->set('prism-harness.agent.model', 'scripted');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
