<?php

declare(strict_types=1);

use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Support\LlmClient;
use FancyFlow\Nodes\Support\ToolInvoker;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;

/**
 * The agent node's TOOL LOOP — which nothing in this package exercised.
 *
 * `AgentExecutor` reads `$response['tool_calls']`, invokes each one and calls
 * the model again. Until 0.42.0 that key was **absent from the `LlmClient`
 * contract**, and the only shipped implementation — `EchoLlmClient` — never
 * returned it. So:
 *
 *   - an implementation written to the contract emitted no `tool_calls`
 *   - the loop therefore returned after ONE step, always
 *   - and the `agent` node degraded silently to a single completion, which
 *     looks exactly like a model that chose not to use a tool
 *
 * Undeclared field, read as if declared. The same shape as `outputShape` before
 * it existed, one layer down — and the same reason it stayed invisible: the
 * degraded behaviour is indistinguishable from a legitimate outcome.
 *
 * No test here could see it either, because every fake in this package was
 * written to the contract that omitted the key. **A test suite cannot catch a
 * contract gap using only fakes built from that contract.** This file exists to
 * break that circle: its client emits `tool_calls`, so the loop runs.
 *
 * Reported by the Prism harness while reviewing v0.41.0 for integration.
 */
final class ScriptedLlmClient implements LlmClient
{
    /** @var list<array<string,mixed>> */
    public array $prompts = [];

    /** @param list<array<string,mixed>> $replies */
    public function __construct(private array $replies) {}

    public function complete(string $prompt, array $options = []): array
    {
        $this->prompts[] = ['prompt' => $prompt, 'options' => $options];

        return array_shift($this->replies) ?? ['text' => 'done'];
    }
}

final class RecordingToolInvoker implements ToolInvoker
{
    /** @var list<array{name:string,args:array<string,mixed>}> */
    public array $calls = [];

    public function invoke(string $name, array $args = []): mixed
    {
        $this->calls[] = ['name' => $name, 'args' => $args];

        return "result of {$name}";
    }
}

function runAgent(array $replies, array $config = []): array
{
    $llm = new ScriptedLlmClient($replies);
    $tools = new RecordingToolInvoker();

    $ctx = new ExecutionContext(
        node: new FlowNode(id: 'a', type: 'agent', config: $config + ['prompt' => 'go']),
        inputs: [],
        emit: static function (): void {},
    );

    $out = (new AgentExecutor($llm, $tools))->execute($ctx);

    return ['out' => $out, 'llm' => $llm, 'tools' => $tools];
}

it('invokes a tool the model asks for, then calls the model again', function () {
    $r = runAgent([
        ['text' => '', 'tool_calls' => [['name' => 'lookup', 'arguments' => ['id' => 7]]]],
        ['text' => 'the answer is 42'],
    ]);

    // The loop actually engaged.
    expect($r['tools']->calls)->toBe([['name' => 'lookup', 'args' => ['id' => 7]]]);
    expect($r['out']['text'])->toBe('the answer is 42');
    expect($r['out']['steps'])->toHaveCount(2);

    // And the second prompt carried the tool's result back.
    expect($r['llm']->prompts[1]['prompt'])->toContain('result of lookup');
});

it('stops on the first reply with no tool_calls', function () {
    $r = runAgent([['text' => 'no tools needed']]);

    expect($r['tools']->calls)->toBe([]);
    expect($r['out']['steps'])->toHaveCount(1);
    expect($r['out'])->not->toHaveKey('truncated');
});

it('marks `truncated` when it hits max_steps still wanting tools', function () {
    // The field that was missing from the declared outputShape until 0.36.0 --
    // pinned here as behaviour, not just as a declaration.
    $wantsTools = ['text' => '', 'tool_calls' => [['name' => 'loop', 'arguments' => []]]];
    $r = runAgent([$wantsTools, $wantsTools, $wantsTools, $wantsTools], ['max_steps' => 2]);

    expect($r['out']['truncated'])->toBeTrue();
    expect($r['out']['steps'])->toHaveCount(2);
    expect($r['tools']->calls)->toHaveCount(2);
});

it('accepts both spellings a provider might send', function () {
    // The executor reads `name`/`tool` and `arguments`/`args`. Both are in the
    // code, so both are pinned -- an adapter written against either survives.
    $r = runAgent([
        ['text' => '', 'tool_calls' => [['tool' => 'legacy', 'args' => ['x' => 1]]]],
        ['text' => 'ok'],
    ]);

    expect($r['tools']->calls)->toBe([['name' => 'legacy', 'args' => ['x' => 1]]]);
});

it('an empty tool_calls list ends the loop rather than looping emptily', function () {
    $r = runAgent([['text' => 'fine', 'tool_calls' => []]]);

    expect($r['out']['steps'])->toHaveCount(1);
    expect($r['out']['text'])->toBe('fine');
});
