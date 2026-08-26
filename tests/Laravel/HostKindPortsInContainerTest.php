<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;

/**
 * The 0.43.0 host-kind fix must work THROUGH THE CONTAINER, not only by hand.
 *
 * 0.43.0 taught `FlowRunner` to resolve kind ports through
 * `$executors->kinds()` instead of the static `NodeKindRegistry::default()`.
 * `tests/Unit/HostKindPortsTest.php` proved it — by constructing
 * `new ExecutorRegistry(kinds: $hostKinds)` itself.
 *
 * **And that is exactly why it could not see the rest of the bug.** In a Laravel
 * app nobody constructs an `ExecutorRegistry`; they resolve one from the
 * container. `FancyFlowServiceProvider` built it via `Builtin::executors(...)`
 * and never passed the container's `NodeKindRegistry` — so `kinds()` fell back
 * to the static builtin catalogue, the host's kinds were invisible again, and a
 * host kind with declared ports published a single `out`.
 *
 * So the fix worked everywhere except the one place its reporter runs.
 *
 * The lesson is the same one that came out of the `tool_calls` contract gap
 * earlier the same day, from the other direction: **a suite that builds its own
 * objects cannot catch a defect in how the framework builds them.** Both tests
 * are needed and neither substitutes for the other.
 *
 * Found by `flabs` on its first end-to-end run against a real Laravel app.
 */
final class ContainerHostKindTestExecutor
{
    public function __invoke(ExecutionContext $ctx): mixed
    {
        // A PLAIN value, not a `Port::` sugar. That is the whole point: the
        // sugars are handled before the declared-ports fallback is reached, so
        // a kind using them routes correctly even when the lookup is broken.
        // Only a plain-returning kind exercises the path that was failing --
        // `for_each` is the builtin of exactly this shape.
        return ['rows' => [1, 2], 'count' => 2];
    }
}

function registerContainerHostKind(): void
{
    app(NodeKindRegistry::class)->register(new NodeKind(
        name: 'deal_list',
        category: 'data',
        label: 'Deal List',
        outputs: [new PortDescriptor('matched'), new PortDescriptor('empty')],
    ));

    app(ExecutorRegistry::class)->bind('deal_list', new ContainerHostKindTestExecutor);
}

/** @return list<string> */
function containerPortsOf($result, string $nodeId): array
{
    $ports = [];

    foreach ($result->events as $event) {
        if ($event->type === 'node-output' && $event->nodeId === $nodeId) {
            $ports[] = $event->portId;
        }
    }

    return $ports;
}

it('gives the container ExecutorRegistry the container NodeKindRegistry', function () {
    // The wiring assertion, stated directly. Everything below follows from it,
    // and asserting it on its own means a failure names the cause rather than a
    // symptom three layers down.
    registerContainerHostKind();

    expect(app(ExecutorRegistry::class)->kinds()->get('deal_list'))->not->toBeNull();

    // And it is the SAME instance, not a copy -- a copy taken at bind time
    // would miss every kind a host registers in its own boot(), which is when
    // hosts actually register them.
    expect(app(ExecutorRegistry::class)->kinds())->toBe(app(NodeKindRegistry::class));
});

it('routes a container-registered host kind through its DECLARED ports', function () {
    // The defect itself: this published `out` in a Laravel app, so every edge
    // leaving `matched` delivered nothing -- no failure, no warning.
    registerContainerHostKind();

    $graph = new FlowGraph([new FlowNode('d', 'deal_list')], []);
    $result = (new FlowRunner)->run($graph, app(ExecutorRegistry::class));

    expect(containerPortsOf($result, 'd'))->toBe(['matched', 'empty']);
});

it('DELIVERS through an edge leaving a container-registered kind port', function () {
    registerContainerHostKind();

    $seen = null;
    app(ExecutorRegistry::class)->bind('sink', function (ExecutionContext $c) use (&$seen) {
        $seen = $c->inputs;

        return 'ok';
    });

    $graph = new FlowGraph(
        [new FlowNode('d', 'deal_list'), new FlowNode('n', 'sink')],
        [new FlowEdge('e1', 'd', 'n', sourceHandle: 'matched')],
    );

    $result = (new FlowRunner)->run($graph, app(ExecutorRegistry::class));

    expect($result->ok)->toBeTrue();
    expect($seen['in'])->toBe(['rows' => [1, 2], 'count' => 2]);
});

it('does NOT warn about an untaken branch of a container-registered kind', function () {
    // The false positive this found. `pass`/`fail` are both declared; a run
    // that took `fail` leaves the `pass` edge unbound, and that is ORDINARY
    // BRANCHING.
    //
    // It warned because `possiblePortIds()` could not find the kind -- so it
    // fell back to `['out']` and concluded `pass` was impossible. **An UNKNOWN
    // kind is not a kind with no ports**, and treating the two alike is the
    // absent-vs-null collapse again, this time inside the warning written to
    // report a different instance of it.
    app(NodeKindRegistry::class)->register(new NodeKind(
        name: 'gate_kind',
        category: 'logic',
        label: 'Gate',
        outputs: [new PortDescriptor('pass'), new PortDescriptor('fail')],
    ));
    app(ExecutorRegistry::class)->bind(
        'gate_kind',
        fn (ExecutionContext $c) => FancyFlow\Runtime\Port::branch('fail', ['why' => 'nope']),
    );
    app(ExecutorRegistry::class)->bind('sink', fn (ExecutionContext $c) => 'ok');

    $graph = new FlowGraph(
        [new FlowNode('g', 'gate_kind'), new FlowNode('p', 'sink'), new FlowNode('f', 'sink')],
        [
            new FlowEdge('e1', 'g', 'p', sourceHandle: 'pass'),
            new FlowEdge('e2', 'g', 'f', sourceHandle: 'fail'),
        ],
    );

    $result = (new FlowRunner)->run($graph, app(ExecutorRegistry::class));

    $warnings = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $warnings[] = $event->message;
        }
    }

    expect($warnings)->toBe([]);
});

it('still warns about a port no kind could ever publish', function () {
    // The guard must not be widened into uselessness by the fix above. A handle
    // that is not a port of a KNOWN kind is still a misconfiguration.
    registerContainerHostKind();
    app(ExecutorRegistry::class)->bind('sink', fn (ExecutionContext $c) => 'ok');

    $graph = new FlowGraph(
        [new FlowNode('d', 'deal_list'), new FlowNode('n', 'sink')],
        [new FlowEdge('e1', 'd', 'n', sourceHandle: 'rows')],
    );

    $result = (new FlowRunner)->run($graph, app(ExecutorRegistry::class));

    $warnings = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $warnings[] = $event->message;
        }
    }

    expect($warnings)->toHaveCount(1);
    // `rows` is a FIELD this node emits, not a port -- the near-miss clause.
    expect($warnings[0])->toContain('"rows"');
});
