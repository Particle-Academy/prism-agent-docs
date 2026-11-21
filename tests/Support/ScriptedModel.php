<?php

declare(strict_types=1);

namespace Tests\Support;

use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * Prism's fake provider, taught to RUN the tool calls it is scripted with.
 *
 * Prism::fake() returns canned responses and never executes a tool, so it
 * cannot test an agent whose behaviour lives in its tools. This is the same
 * fake (it extends PrismFake and installs itself the way Prism::fake() does)
 * with one addition: a scripted turn that names tool calls has them executed
 * against the tools the request actually offered, through Prism's own
 * CallsTools, exactly as a real provider handler does. So the tools a test
 * reaches are the ones the harness resolved for the session, with whatever
 * authorisation wraps them, not ones the test built.
 *
 * No network and no model: every "decision" is in the script.
 */
final class ScriptedModel extends PrismFake
{
    use CallsTools;

    /** @var list<TextRequest> */
    private array $requests = [];

    /** @var list<array{name: string, arguments: array<string, mixed>, result: string}> */
    private array $calls = [];

    /** @param list<list<ToolCall>|string> $script each turn: the tool calls the model makes, or its closing text */
    public function __construct(private array $script)
    {
        parent::__construct([]);
    }

    /** @param list<list<ToolCall>|string> $script */
    public static function install(array $script): self
    {
        $fake = new self($script);
        app()->instance(PrismManager::class, new class($fake) extends PrismManager
        {
            public function __construct(private readonly ScriptedModel $fake) {}

            public function resolve(mixed $name, array $providerConfig = []): ScriptedModel
            {
                $this->fake->setProviderConfig($providerConfig);

                return $this->fake;
            }
        });

        return $fake;
    }

    /** One tool call, as a model would make it. */
    public static function call(string $name, array $arguments = []): ToolCall
    {
        static $n = 0;
        $n++;

        return new ToolCall('call_'.$n, $name, $arguments);
    }

    public function text(TextRequest $request): TextResponse
    {
        $this->requests[] = $request;
        $steps = [];
        $toolCalls = [];
        $toolResults = [];
        $messages = [];

        for ($i = 0; $i < max(1, $request->maxSteps()); $i++) {
            $turn = array_shift($this->script) ?? '';
            if (is_string($turn)) {
                $steps[] = $this->step($turn, FinishReason::Stop, [], []);
                $messages[] = new AssistantMessage($turn);

                return $this->response($steps, $turn, FinishReason::Stop, $toolCalls, $toolResults, $messages);
            }

            $results = $this->callTools($request->tools(), $turn);
            foreach ($results as $result) {
                $this->calls[] = ['name' => $result->toolName, 'arguments' => $result->args, 'result' => is_string($result->result) ? $result->result : (string) json_encode($result->result)];
            }
            $steps[] = $this->step('', FinishReason::ToolCalls, $turn, $results);
            $messages[] = new AssistantMessage('', $turn);
            $messages[] = new ToolResultMessage($results);
            array_push($toolCalls, ...$turn);
            array_push($toolResults, ...$results);
        }

        return $this->response($steps, '', FinishReason::ToolCalls, $toolCalls, $toolResults, $messages);
    }

    /** @return list<TextRequest> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return list<array{name: string, arguments: array<string, mixed>, result: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** @return list<array<string, mixed>> the decoded results of every call to $name */
    public function resultsOf(string $name): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            if ($call['name'] === $name) {
                $decoded = json_decode($call['result'], true);
                $out[] = is_array($decoded) ? $decoded : ['raw' => $call['result']];
            }
        }

        return $out;
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    /**
     * @param  list<ToolCall>  $calls
     * @param  list<ToolResult>  $results
     */
    private function step(string $text, FinishReason $reason, array $calls, array $results): Step
    {
        return new Step(
            text: $text,
            finishReason: $reason,
            toolCalls: $calls,
            toolResults: $results,
            providerToolCalls: [],
            usage: new Usage(1, 1),
            meta: new Meta('scripted', 'scripted'),
            messages: [],
            systemPrompts: [],
        );
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     * @param  list<AssistantMessage|ToolResultMessage>  $messages
     */
    private function response(array $steps, string $text, FinishReason $reason, array $toolCalls, array $toolResults, array $messages): TextResponse
    {
        if ($steps === []) {
            throw new RuntimeException('The script ran out.');
        }

        return new TextResponse(
            steps: collect($steps),
            text: $text,
            finishReason: $reason,
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: new Usage(count($steps), count($steps)),
            meta: new Meta('scripted', 'scripted'),
            messages: collect($messages),
        );
    }
}
