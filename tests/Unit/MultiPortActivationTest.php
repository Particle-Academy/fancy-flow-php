<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\Port;
use FancyFlow\Schema\PortDescriptor;
use FancyFlow\Workflow;

/**
 * A node can activate a CHOSEN SUBSET of its ports (#18, reported by MOIC).
 *
 * `activatedPorts` knew two answers: `__port` / `branch` lit exactly ONE port,
 * and anything else lit EVERY declared port. There was no way to say "these two
 * of five", and `Port::only` / `Port::branch` both take a single string, so the
 * sugar could not express it either.
 *
 * The case it blocks is ordinary: a change lands on a shared piece of
 * configuration and the run has to wake the lanes whose subject matter actually
 * matched — known only at run time. Waking one lane silently drops the rest of
 * the work; waking all of them does work nobody asked for.
 *
 * `Port::many(['a', 'c'])` lights both with one payload; `Port::many(['a' => $x,
 * 'c' => $y])` gives each its own. An explicitly empty list lights nothing,
 * which is the rule an explicitly empty `outputs` array already follows.
 */

/**
 * A router with five declared ports; A/B/C collect from a, b and c.
 *
 * @param  callable(): mixed  $route
 * @return array<string,mixed> what each sink received, by node id
 */
function runRouterWith(callable $route): array
{
    $graph = Workflow::import([
        '$schema' => 'https://particle.academy/schemas/workflow/v1.json',
        'version' => 1,
        'graph' => [
            'nodes' => [
                ['id' => 'r', 'kind' => 'router'],
                ['id' => 'A', 'kind' => 'sink'],
                ['id' => 'B', 'kind' => 'sink'],
                ['id' => 'C', 'kind' => 'sink'],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'r', 'target' => 'A', 'sourceHandle' => 'a'],
                ['id' => 'e2', 'source' => 'r', 'target' => 'B', 'sourceHandle' => 'b'],
                ['id' => 'e3', 'source' => 'r', 'target' => 'C', 'sourceHandle' => 'c'],
            ],
        ],
    ])->graph;

    $seen = [];

    // The ports are declared on the KIND, as a host registers them. The document
    // may also carry them, but this import drops node-level `outputs` (see the
    // note in Workflow::import) -- a separate divergence from the TS import,
    // filed rather than folded in here.
    $kinds = new NodeKindRegistry();
    $kinds->register(new NodeKind(
        name: 'router',
        category: 'logic',
        label: 'Router',
        outputs: [
            new PortDescriptor('a'),
            new PortDescriptor('b'),
            new PortDescriptor('c'),
            new PortDescriptor('d'),
            new PortDescriptor('e'),
        ],
    ));

    $executors = (new ExecutorRegistry(kinds: $kinds))
        ->bind('router', fn (): mixed => $route())
        // The RAW port, read with array_key_exists: `??` cannot tell a null
        // payload from an absent one, which is half of what these rows assert.
        ->bind('sink', function ($ctx) use (&$seen): mixed {
            $seen[$ctx->node->id] = array_key_exists('in', $ctx->inputs) ? $ctx->inputs['in'] : '__absent__';

            return null;
        });

    (new FlowRunner())->run($graph, $executors);

    return $seen;
}

it('lights the listed ports and leaves the rest dark', function () {
    $seen = runRouterWith(fn () => Port::many(['a', 'c'], ['matched' => true]));

    expect($seen['A'])->toBe(['matched' => true]);
    expect($seen['C'])->toBe(['matched' => true]);
    expect($seen)->not->toHaveKey('B');
});

it('gives each lit port its own payload when handed a map', function () {
    $seen = runRouterWith(fn () => Port::many(['a' => ['queue' => 'billing'], 'c' => ['queue' => 'abuse']]));

    expect($seen['A'])->toBe(['queue' => 'billing']);
    expect($seen['C'])->toBe(['queue' => 'abuse']);
    expect($seen)->not->toHaveKey('B');
});

it('carries a per-port payload of null rather than falling back to the result', function () {
    // The distinction `branch` had to learn, per port this time: present-and-null
    // is a payload. `?? $value` would hand that port the shared value instead.
    $seen = runRouterWith(fn () => Port::many(['a' => null]));

    expect($seen)->toHaveKey('A');
    expect($seen['A'])->toBeNull();
});

it('lights nothing for an explicitly empty list', function () {
    expect(runRouterWith(fn () => Port::many([])))->toBe([]);
});

it('reads the raw wire shape, not only the sugar', function () {
    // A host in another language emits the shape directly; the engine is what
    // has to agree, so the rows assert the shape rather than the helper.
    $seen = runRouterWith(fn () => ['__ports' => ['a', 'c'], 'value' => 'v']);

    expect($seen['A'])->toBe('v');
    expect($seen['C'])->toBe('v');
    expect($seen)->not->toHaveKey('B');
});

it('leaves the one-port and every-port rules exactly as they were', function () {
    expect(runRouterWith(fn () => Port::only('b', 'only-b')))->toBe(['B' => 'only-b']);

    $all = runRouterWith(fn () => ['plain' => true]);
    ksort($all);
    expect(array_keys($all))->toBe(['A', 'B', 'C']);
});
