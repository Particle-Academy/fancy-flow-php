<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\FakeLlmClient;
use FancyFlow\Capabilities\LlmClient as RouteLlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Laravel\EloquentWorkflowResolver;
use FancyFlow\Laravel\Facades\FancyFlow;

afterEach(fn () => Capabilities::reset());

function flowNode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

function flowSchema(array $nodes, array $edges = []): array
{
    return ['version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

it('uses an LLM client bound in the container for llm_router', function () {
    // The container binding is the idiomatic Laravel seam; the core can't reach
    // into a container, so the provider forwards it explicitly.
    app()->instance(RouteLlmClient::class, new FakeLlmClient([new LlmRouteChoice('billing', 'invoice')]));
    app()->make(\FancyFlow\Laravel\FancyFlowServiceProvider::class, ['app' => app()])->boot();

    $result = FancyFlow::run(
        flowSchema(
            [
                flowNode('t', 'manual_trigger'),
                // Deliberately the LEGACY id: an end-to-end run of a graph
                // saved before the llm_router rename must still route.
                flowNode('r', 'llm_branch', [
                    'prompt' => 'charged twice',
                    'routes' => [['port' => 'billing'], ['port' => 'support']],
                ]),
                flowNode('done', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'r'],
                ['id' => 'e2', 'source' => 'r', 'target' => 'done', 'sourceHandle' => 'billing'],
            ],
        ),
        ['t' => ['ticket' => 1]],
    );

    expect($result->ok)->toBeTrue();
    expect($result->outputs['r']['value']['route'])->toBe('billing');
    // The chosen port actually carried the run onwards.
    expect($result->outputs)->toHaveKey('done');
});

it('resolves a subflow reference from the stored workflows table', function () {
    config()->set('fancy-flow.persistence.enabled', true);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    $this->artisan('migrate')->run();

    \FancyFlow\Laravel\Models\Workflow::create([
        'name' => 'greet',
        'version' => 1,
        'schema' => flowSchema([flowNode('inner', 'output')]),
    ]);

    $resolver = new EloquentWorkflowResolver(app(\FancyFlow\NodeKindRegistry::class));

    expect($resolver->resolve('greet'))->not->toBeNull()
        ->and($resolver->resolve('greet')->nodes[0]->id)->toBe('inner')
        ->and($resolver->resolve('no-such-flow'))->toBeNull();
})->skip(fn () => ! class_exists(\Illuminate\Database\Eloquent\Model::class), 'needs Eloquent');

it('binds an Eloquent workflow resolver by default, overridable by the app', function () {
    expect(app(WorkflowResolver::class))->toBeInstanceOf(EloquentWorkflowResolver::class);
});
