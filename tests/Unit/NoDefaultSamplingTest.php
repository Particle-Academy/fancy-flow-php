<?php

declare(strict_types=1);

/**
 * No sampling parameter unless the author sets one.
 *
 * `llm_call` and `agent` declared `temperature` with `'default' => 0.7`.
 * `defaultConfigFor()` copies every declared default into a fresh node, so
 * `FlowAuthoring::addNode()` (and through it fancy-flow-mcp's `add_node`) and
 * `Workflow::import()` for a node saved without a config wrote 0.7 into nodes
 * nobody had configured, and the executor forwarded it to the client. Current
 * Anthropic models reject a request that sets `temperature`, so a live flow
 * failed on its first call.
 *
 * The default is now ABSENT. An explicit value still passes through untouched:
 * whether a provider accepts it is the adapter's call, not the engine's.
 */

use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Ai\LlmCallExecutor;
use FancyFlow\Nodes\Support\EchoToolInvoker;
use FancyFlow\Nodes\Support\LlmClient;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Workflow;

const SAMPLING_KEYS = ['temperature', 'top_p', 'top_k'];

function samplingRegistry(): NodeKindRegistry
{
    return FlowAuthoring::default()->kinds();
}

final class RecordingLlmClient implements LlmClient
{
    /** @var list<array<string,mixed>> */
    public array $options = [];

    public function complete(string $prompt, array $options = []): array
    {
        $this->options[] = $options;

        return ['text' => 'ok'];
    }
}

it('gives a fresh node of each LLM kind no temperature', function (string $kind) {
    $authoring = FlowAuthoring::default();
    $draft = $authoring->create('wf_sampling', 'Sampling');

    $node = $authoring->addNode($draft, $kind)['node'];

    expect($node['config'])->not->toHaveKey('temperature');
    // The field is still declared, so an author can set it.
    $keys = array_map(fn ($f) => $f->key, $authoring->kinds()->get($kind)->configSchema);
    expect($keys)->toContain('temperature');
})->with(['llm_call', 'agent']);

it('leaves the other llm_call defaults alone', function () {
    $authoring = FlowAuthoring::default();
    $node = $authoring->addNode($authoring->create('wf_sampling', 'Sampling'), 'llm_call')['node'];

    expect($node['config']['max_tokens'])->toBe(1024);
});

it('declares no default for any sampling parameter on any kind', function () {
    $registry = samplingRegistry();
    $kinds = $registry->all();
    // Vacuity guard: an empty registry would pass the loop below.
    expect(count($kinds))->toBeGreaterThan(10);

    foreach ($kinds as $kind) {
        foreach ($kind->configSchema as $field) {
            if (in_array($field->key, SAMPLING_KEYS, true)) {
                expect($field->hasDefault())->toBeFalse("{$kind->name}.{$field->key}");
            }
        }
        $fresh = $registry->defaultConfigFor($kind);
        foreach (SAMPLING_KEYS as $key) {
            expect($fresh)->not->toHaveKey($key);
        }
    }
});

it('reports no temperature in describeKind()\'s defaultConfig', function () {
    $authoring = FlowAuthoring::default();
    $manifest = $authoring->describeKind($authoring->kinds()->get('llm_call'));

    expect($manifest['defaultConfig'])->not->toHaveKey('temperature');
});

function samplingDoc(?array $config): array
{
    $llm = ['id' => 'llm', 'kind' => 'llm_call', 'position' => ['x' => 1, 'y' => 0]];
    if ($config !== null) {
        $llm['config'] = $config;
    }

    return [
        'version' => 1,
        'graph' => [
            'nodes' => [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]], $llm],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'llm']],
        ],
    ];
}

it('does not invent a temperature when importing a node saved without config', function () {
    $result = Workflow::import(samplingDoc(null), registry: samplingRegistry());

    expect($result->graph->node('llm')->config)->not->toHaveKey('temperature');
});

it('keeps an explicit temperature on import', function () {
    $result = Workflow::import(samplingDoc(['model' => 'm', 'prompt' => 'p', 'temperature' => 0.7]), registry: samplingRegistry());

    expect($result->graph->node('llm')->config['temperature'])->toBe(0.7);
});

function samplingRun(string $kind, array $config): array
{
    $client = new RecordingLlmClient();
    $ctx = new ExecutionContext(new FlowNode(id: 'n', type: $kind, config: $config), [], static function (): void {});
    $executor = $kind === 'agent' ? new AgentExecutor($client, new EchoToolInvoker()) : new LlmCallExecutor($client);
    $executor->execute($ctx);

    return $client->options[0];
}

it('sends no sampling parameter for a freshly added node', function (string $kind) {
    $authoring = FlowAuthoring::default();
    $config = $authoring->addNode($authoring->create('wf_sampling', 'Sampling'), $kind)['node']['config'];

    $options = samplingRun($kind, $config + ['model' => 'm', 'prompt' => 'p']);

    foreach (SAMPLING_KEYS as $key) {
        expect($options)->not->toHaveKey($key);
    }
})->with(['llm_call', 'agent']);

it('passes an explicit temperature through unchanged', function (string $kind) {
    expect(samplingRun($kind, ['model' => 'm', 'prompt' => 'p', 'temperature' => 0.2])['temperature'])->toBe(0.2);
})->with(['llm_call', 'agent']);
