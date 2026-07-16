<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Laravel\Events\NodeStatusChanged;
use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\Events\WorkflowStarted;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Workflow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function schema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function node(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

it('resolves the manager and facade', function () {
    expect(app('fancy-flow'))->toBeInstanceOf(FancyFlowManager::class);
    expect(FancyFlow::kinds()->all())->toHaveCount(24); // 22 built-ins + note + subgraph
});

it('runs a workflow through the container executors', function () {
    $result = FancyFlow::run(
        schema(
            [node('t', 'manual_trigger'), node('tf', 'transform', ['expression' => '{{ $json.name }}']), node('o', 'output')],
            [['id' => 'e1', 'source' => 't', 'target' => 'tf'], ['id' => 'e2', 'source' => 'tf', 'target' => 'o']],
        ),
        ['t' => ['name' => 'Ada']],
    );

    expect($result->ok)->toBeTrue();
    expect($result->output('o'))->toBe('Ada');
});

it('dispatches Laravel events for a run', function () {
    Event::fake([WorkflowStarted::class, NodeStatusChanged::class, WorkflowFinished::class]);

    FancyFlow::run(schema([node('t', 'manual_trigger')]), ['t' => ['x' => 1]]);

    Event::assertDispatched(WorkflowStarted::class);
    Event::assertDispatched(NodeStatusChanged::class);
    Event::assertDispatched(WorkflowFinished::class, fn (WorkflowFinished $e) => $e->ok === true);
});

it('routes api_request through Laravel HTTP (fakeable)', function () {
    Http::fake(['api.example.com/*' => Http::response(['pong' => true], 200)]);

    $result = FancyFlow::run(
        schema([node('t', 'manual_trigger'), node('api', 'api_request', ['method' => 'GET', 'url' => 'https://api.example.com/ping'])],
            [['id' => 'e1', 'source' => 't', 'target' => 'api']]),
        ['t' => []],
    );

    expect($result->ok)->toBeTrue();
    expect($result->output('api')['status'])->toBe(200);
    expect($result->output('api')['body'])->toBe(['pong' => true]);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.example.com/ping');
});

it('persists memory_store through the cache across runs', function () {
    $write = schema([node('t', 'manual_trigger'), node('w', 'memory_store', ['operation' => 'write', 'key' => 'pref', 'value' => '{{ $json.pref }}'])],
        [['id' => 'e1', 'source' => 't', 'target' => 'w']]);
    FancyFlow::run($write, ['t' => ['pref' => 'dark']]);

    // A separate run reads it back — proving cross-run persistence via the cache.
    $read = schema([node('r', 'memory_store', ['operation' => 'read', 'key' => 'pref'])]);
    $result = FancyFlow::run($read, ['r' => []]);

    expect($result->output('r'))->toBe('dark');
});

it('extends with a container-resolved executor that gets constructor DI', function () {
    FancyFlow::extend('greet', GreetExecutor::class, [
        'name' => 'greet', 'category' => 'logic', 'label' => 'Greet',
    ]);

    $result = FancyFlow::run(
        schema([node('t', 'manual_trigger'), node('g', 'greet'), node('o', 'output')],
            [['id' => 'e1', 'source' => 't', 'target' => 'g'], ['id' => 'e2', 'source' => 'g', 'target' => 'o']]),
        ['t' => ['name' => 'world']],
    );

    expect($result->ok)->toBeTrue();
    expect($result->output('o'))->toBe('hi world');
});

it('registers kinds from config', function () {
    config()->set('fancy-flow.kinds', [
        ['name' => 'my_kind', 'category' => 'io', 'label' => 'My Kind'],
    ]);

    expect(FancyFlow::kinds()->has('my_kind'))->toBeTrue();
});

/** A service the executor depends on — proves container DI through ContainerResolver. */
final class GreetService
{
    public function greet(string $name): string
    {
        return "hi {$name}";
    }
}

final class GreetExecutor implements NodeExecutor
{
    public function __construct(private readonly GreetService $service) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $input = $ctx->input('in');

        return $this->service->greet((string) ($input['name'] ?? $input));
    }
}
