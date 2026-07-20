<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\FakeLlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\Nodes\Ai\LlmRouterExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\FlowNode;

afterEach(fn () => Capabilities::reset());

/** Run an executor, capturing emitted events. */
function llmExec(NodeExecutor $exec, array $config, array $inputs = [], ?array &$events = null): mixed
{
    $captured = [];
    $result = $exec->execute(new ExecutionContext(
        new FlowNode(id: 'router', type: 'llm_router', config: $config),
        $inputs,
        function (RunEvent $e) use (&$captured) { $captured[] = $e; },
    ));
    $events = $captured;

    return $result;
}

function twoRoutes(array $extra = []): array
{
    return array_merge([
        'prompt' => 'My invoice charged me twice.',
        'routes' => [
            ['port' => 'billing', 'description' => 'Invoices, refunds, payments.'],
            ['port' => 'support', 'description' => 'Anything technical.'],
        ],
    ], $extra);
}

it('routes down the port the model chose, carrying the reason with the value', function () {
    $client = new FakeLlmClient([new LlmRouteChoice('billing', 'Duplicate charge.')]);

    $result = llmExec(new LlmRouterExecutor($client), twoRoutes(), ['in' => ['ticket' => 7]]);

    // The reason travels WITH the value so a completed run explains itself
    // without the model call being replayed.
    expect($result)->toBe([
        '__port' => 'billing',
        'value' => ['route' => 'billing', 'reason' => 'Duplicate charge.', 'input' => ['in' => ['ticket' => 7]]],
    ]);
});

it('never routes to a port the model invented — falls back and warns', function () {
    // The failure this guards against: emitting on a port with no edge silently
    // ends the branch, and the run then reports SUCCESS having done nothing.
    $client = FakeLlmClient::always('escalate');

    $result = llmExec(new LlmRouterExecutor($client), twoRoutes(), events: $events);

    expect($result['__port'])->toBe('fallback')
        ->and($result['value']['reason'])->toBe('unrecognised route "escalate"');

    $warnings = array_filter($events, fn (RunEvent $e) => $e->type === RunEvent::LOG && $e->level === 'warn');
    expect($warnings)->toHaveCount(1);
    expect(reset($warnings)->message)
        ->toContain('model returned "escalate"')
        ->toContain('Routing to "fallback"');
});

it('falls back to the first declared route when the fallback port is switched off', function () {
    $result = llmExec(
        new LlmRouterExecutor(FakeLlmClient::always('nonsense')),
        twoRoutes(['fallback' => false]),
        events: $events,
    );

    expect($result['__port'])->toBe('billing');
    expect(array_filter($events, fn (RunEvent $e) => $e->level === 'warn'))->toHaveCount(1);
});

it('treats an empty choice as unrecognised rather than as a port', function () {
    $result = llmExec(new LlmRouterExecutor(FakeLlmClient::always('')), twoRoutes(), events: $events);

    expect($result['__port'])->toBe('fallback');
    expect(reset($events)->message)->toContain('model returned "(nothing)"');
});

it('aborts when no routes are configured', function () {
    expect(fn () => llmExec(new LlmRouterExecutor(FakeLlmClient::always('x')), ['prompt' => 'hi']))
        ->toThrow(RunAborted::class, 'llm_router has no routes configured');
});

it('aborts with an actionable message when no LLM client is available', function () {
    // Never guess a branch: a silent default would look like the model chose.
    Capabilities::setLlmClient(null);
    Capabilities::configureLlm(['driver' => 'nonexistent-driver']);

    expect(fn () => llmExec(new LlmRouterExecutor(), twoRoutes()))
        ->toThrow(RunAborted::class, 'not installed');
});

it('hands the node config through to the client as the route request', function () {
    $client = new FakeLlmClient([new LlmRouteChoice('support')]);

    llmExec(new LlmRouterExecutor($client), twoRoutes([
        'system' => 'Route the ticket.',
        'provider' => 'openai',
        'model' => 'gpt-5',
        'credential' => 'creds/openai',
    ]));

    $request = $client->requests[0];
    expect($request->system)->toBe('Route the ticket.')
        ->and($request->provider)->toBe('openai')
        ->and($request->model)->toBe('gpt-5')
        ->and($request->credential)->toBe('creds/openai')
        ->and($request->ports())->toBe(['billing', 'support']);
});

it('routes on the inputs when no prompt is configured', function () {
    $client = new FakeLlmClient([new LlmRouteChoice('billing')]);

    llmExec(new LlmRouterExecutor($client), ['routes' => twoRoutes()['routes']], ['in' => 'charged twice']);

    expect($client->requests[0]->prompt)->toBe('{"in":"charged twice"}');
});

it('drops blank routes so a half-typed one cannot become real', function () {
    $routes = LlmRouterExecutor::declaredRoutes(['routes' => [
        ['port' => 'billing'], ['port' => '  '], ['port' => ''], ['port' => 'support'],
    ]]);

    expect(array_map(fn ($r) => $r->port, $routes))->toBe(['billing', 'support']);
});

it('derives ports from the routes, de-duplicated, with fallback last', function () {
    $ports = fn (array $config) => array_map(fn ($p) => $p->id, LlmRouterExecutor::ports($config));

    expect($ports(twoRoutes()))->toBe(['billing', 'support', 'fallback']);
    expect($ports(twoRoutes(['fallback' => false])))->toBe(['billing', 'support']);
    expect($ports(['routes' => [['port' => 'a'], ['port' => 'a']], 'fallback' => false]))->toBe(['a']);
    expect($ports([]))->toBe(['fallback']);
});
