<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Workflow;

/*
 * Node grouping -- `parentId` and the visual fields beside it.
 *
 * Two guarantees, and until 2026-09-05 this runtime kept neither. The Python
 * twin had the identical pair, found while porting terminal lanes; this is the
 * same fix on this side.
 *
 * **It must survive a round trip.** The WorkflowSchema's own comment said these
 * fields exist "purely for the canvas", so "a runtime that only walks edges and
 * ports ignores all of these". This runtime read that literally: `parentId` was
 * neither imported nor exported, so a graph loaded here and saved back lost
 * every grouping a person had drawn -- silently and completely. A tool that
 * round-trips a workflow is the normal case, not an exotic one.
 *
 * **A lane must not fail the run.** The TypeScript engine ships
 * `@particle-academy/lane` and walks straight past it. This runner skipped only
 * `note`, so the same WorkflowSchema that ran on Node failed here with
 * "No executor registered for kind=lane".
 *
 * `GraphConnectivity::mayFloat()` already knew -- its comment says "a laned
 * graph authored in the TS editor carries `lane` nodes that PHP's registry does
 * not have". It exempted them from connectivity errors and the runner still
 * refused to run them. `RunEvent` even documents a "lane" status text that
 * nothing had ever emitted. The analysis knew, the runner did not, and nothing
 * compared them.
 */

function lanedDocument(): array
{
    return [
        '$schema' => 'https://particle.academy/schemas/workflow/v1.json',
        'version' => 1,
        'graph' => [
            'nodes' => [
                [
                    'id' => 'lane',
                    'kind' => 'lane',
                    'position' => ['x' => 0, 'y' => 0],
                    'width' => 480,
                    'height' => 220,
                    'style' => ['background' => '#eef'],
                    'config' => ['title' => 'Setup'],
                ],
                [
                    'id' => 't',
                    'kind' => 'manual_trigger',
                    'position' => ['x' => 20, 'y' => 40],
                    'parentId' => 'lane',
                    'extent' => 'parent',
                ],
            ],
            'edges' => [],
        ],
    ];
}

it('carries parentId through import', function () {
    $imported = Workflow::import(lanedDocument());

    $inside = collect($imported->graph->nodes)->firstWhere('id', 't');

    expect($inside->parentId)->toBe('lane');
    expect($inside->extent)->toBe('parent');
});

it('round-trips the whole visual block', function () {
    // Carried together, because half a restoration is harder to notice: a child
    // that kept its parent but lost its containment rule, or a lane back at the
    // default size, reads as a canvas somebody nudged rather than as data a
    // tool destroyed.
    $out = Workflow::export(Workflow::import(lanedDocument())->graph);

    $byId = collect($out['graph']['nodes'])->keyBy('id');

    expect($byId['t']['parentId'])->toBe('lane');
    expect($byId['t']['extent'])->toBe('parent');
    expect($byId['lane']['width'])->toBe(480.0);
    expect($byId['lane']['height'])->toBe(220.0);
    expect($byId['lane']['style'])->toBe(['background' => '#eef']);
});

it('gains no empty keys on a plain node', function () {
    // A graph of ordinary plumbing should not carry five empty keys per node,
    // or every saved diff becomes unreadable.
    $out = Workflow::export(new FlowGraph([new FlowNode(id: 'n', type: 'log')], []));

    $written = $out['graph']['nodes'][0];

    foreach (['parentId', 'extent', 'width', 'height', 'style'] as $key) {
        expect($written)->not->toHaveKey($key);
    }
});

it('runs a graph containing a lane', function () {
    // The parity failure, stated as the thing a consumer would hit.
    //
    // The trigger IS bound, deliberately: the point is that the lane is walked
    // past while the rest of the graph runs normally. An empty registry would
    // fail on the trigger and prove nothing about the lane.
    $executors = (new ExecutorRegistry())->bind('manual_trigger', static fn (): array => ['ok' => true]);

    $result = (new FlowRunner())->run(Workflow::import(lanedDocument())->graph, $executors);

    expect($result->ok)->toBeTrue();
    expect($result->error)->toBeNull();
    // The lane produced no output and did not stop the run.
    expect($result->outputs)->toHaveKey('t');
    expect($result->outputs)->not->toHaveKey('lane');
});

it('skips a lane under every id it answers to, with an empty registry', function () {
    // A caller's registry may not have a `lane` kind at all -- PHP has never
    // declared one -- so the id match is what makes this hold rather than a
    // category lookup that would answer "unknown".
    foreach ([
        'lane',
        '@particle-academy/lane',
        '@fancy/lane',
        'terminal_lane',
        '@particle-academy/terminal_lane',
    ] as $kind) {
        $graph = new FlowGraph([new FlowNode(id: 'l', type: $kind)], []);

        $result = (new FlowRunner())->run($graph, new ExecutorRegistry());

        expect($result->ok)->toBeTrue("{$kind} did not run clean");
    }
});

it('does NOT skip an unknown kind', function () {
    // Running is the default. `mayFloat` lets an unknown kind float because it
    // cannot know what the kind is -- the honest answer to a different
    // question. Skipping here would turn every typo in a kind id into a node
    // that silently does nothing while the run reports success.
    $graph = new FlowGraph([new FlowNode(id: 'n', type: '@acme/never-heard-of-it')], []);

    $result = (new FlowRunner())->run($graph, new ExecutorRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('No executor registered');
});
