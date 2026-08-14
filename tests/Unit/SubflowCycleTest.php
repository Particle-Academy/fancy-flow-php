<?php

declare(strict_types=1);

use FancyFlow\Analysis\SubflowCycle;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Schema\FlowGraph;

/**
 * Static subflow-cycle detection — issue #5.
 *
 * A `subflow` loop was only ever caught at RUNTIME, by the depth cap in
 * SubflowExecutor. By the time that fires the work has already been done N times
 * over: every node above the subflow ran on each pass, including the
 * side-effectful ones — writes, webhooks, notifications, LLM calls. The author
 * gets an opaque failure from deep inside a run, and the graph that caused it is
 * still saved and still runnable.
 *
 * `Workflow::import()` cannot see this: it validates ONE schema in isolation, and
 * A → B → A is made of two individually valid graphs. Detecting it needs the
 * resolver, which only the host has — so the check belongs here, where the
 * `subflow` config key and the ref/version rules are already the package's
 * contract, rather than in each host.
 *
 * The depth cap stays. It is the right backstop for a loop created from the
 * other end (someone edits B after A was saved), which no authoring-time check
 * can catch. It is not a substitute for refusing to WRITE one.
 */

/** A resolver over a fixed ref → graph map; unknown refs resolve to null. */
function cycleResolver(array $graphs): WorkflowResolver
{
    return new class($graphs) implements WorkflowResolver
    {
        public function __construct(private array $graphs) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            $key = $version === null ? $ref : "$ref@$version";

            return $this->graphs[$key] ?? $this->graphs[$ref] ?? null;
        }
    };
}

/** A graph whose single subflow node points at `$ref`. */
function callsSubflow(string $ref, ?int $version = null, string $type = 'subflow'): FlowGraph
{
    $config = ['workflow' => $ref];
    if ($version !== null) {
        $config['version'] = $version;
    }

    return ffGraph([ffNode('sf', $type, $config)]);
}

it('returns an empty chain for a graph with no subflow at all', function () {
    $graph = ffGraph([ffNode('a', '@particle-academy/output')]);

    expect(SubflowCycle::find($graph, cycleResolver([])))->toBe([]);
});

it('returns an empty chain for a subflow that does not loop', function () {
    $resolver = cycleResolver([
        'Digest' => ffGraph([ffNode('out', '@particle-academy/output')]),
    ]);

    expect(SubflowCycle::find(callsSubflow('Digest'), $resolver, 'Daily Planner'))->toBe([]);
});

it('names the chain for a workflow that references itself directly', function () {
    $resolver = cycleResolver(['Daily Planner' => callsSubflow('Daily Planner')]);

    expect(SubflowCycle::find(callsSubflow('Daily Planner'), $resolver, 'Daily Planner'))
        ->toBe(['Daily Planner', 'Daily Planner']);
});

it('names the whole chain for an indirect loop, closing on the repeated ref', function () {
    // Daily Planner → Digest → Daily Planner. The CHAIN is the point: "this
    // loops" is far less useful than naming the step that closes it.
    $resolver = cycleResolver([
        'Digest' => callsSubflow('Daily Planner'),
        'Daily Planner' => callsSubflow('Digest'),
    ]);

    expect(SubflowCycle::find(callsSubflow('Digest'), $resolver, 'Daily Planner'))
        ->toBe(['Daily Planner', 'Digest', 'Daily Planner']);
});

it('treats an unresolvable ref as safe rather than as a loop', function () {
    // A missing workflow is a different problem, reported elsewhere. Refusing a
    // save for it would block authoring a parent before its child exists.
    expect(SubflowCycle::find(callsSubflow('nope'), cycleResolver([])))->toBe([]);
});

it('treats a resolution FAILURE as safe too', function () {
    $resolver = new class implements WorkflowResolver
    {
        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return new WorkflowResolutionFailure(
                WorkflowResolutionFailure::VERSION_MISMATCH,
                available: 3,
            );
        }
    };

    expect(SubflowCycle::find(callsSubflow('Pinned', 1), $resolver))->toBe([]);
});

it('does not report a diamond as a cycle', function () {
    // A → B and A → C, both of which call D. D is visited twice but is never on
    // its own path. Keying "seen" globally instead of per-path would call this a
    // loop and refuse a perfectly good graph.
    $leaf = ffGraph([ffNode('out', '@particle-academy/output')]);
    $resolver = cycleResolver([
        'B' => callsSubflow('D'),
        'C' => callsSubflow('D'),
        'D' => $leaf,
    ]);
    $root = ffGraph([
        ffNode('b', 'subflow', ['workflow' => 'B']),
        ffNode('c', 'subflow', ['workflow' => 'C']),
    ]);

    expect(SubflowCycle::find($root, $resolver, 'A'))->toBe([]);
});

it('distinguishes version pins, so A@1 → A@2 is not a loop on its own', function () {
    // Two pinned versions are different interfaces. Collapsing them would refuse
    // a legitimate "call the previous version" graph.
    $resolver = cycleResolver([
        'A@2' => ffGraph([ffNode('out', '@particle-academy/output')]),
    ]);

    expect(SubflowCycle::find(callsSubflow('A', 2), $resolver, 'A'))->toBe([]);
});

it('follows the namespaced kind id as well as the bare one', function () {
    // Kind ids are namespaced with bare aliases, so a detector that matched only
    // one spelling would silently stop seeing half the graphs in the wild.
    $resolver = cycleResolver(['Loop' => callsSubflow('Loop', null, '@particle-academy/subflow')]);

    expect(SubflowCycle::find(callsSubflow('Loop', null, '@particle-academy/subflow'), $resolver, 'Loop'))
        ->toBe(['Loop', 'Loop']);
});

it('descends into an inline subgraph to find a subflow nested inside it', function () {
    // `subgraph` embeds its graph in config rather than naming one, so it cannot
    // cycle by itself — but a `subflow` INSIDE one can, and a walker that only
    // looked at top-level nodes would miss it entirely.
    $resolver = cycleResolver(['Root' => ffGraph([
        ffNode('sg', 'subgraph', ['graph' => [
            'nodes' => [
                ['id' => 'inner', 'type' => 'subflow', 'config' => ['workflow' => 'Root']],
            ],
            'edges' => [],
        ]]),
    ])]);

    expect(SubflowCycle::find($resolver->resolve('Root'), $resolver, 'Root'))
        ->toBe(['Root', 'Root']);
});
