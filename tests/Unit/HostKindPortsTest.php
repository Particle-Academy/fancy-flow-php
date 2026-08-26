<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\PortDescriptor;

/**
 * A HOST-registered kind's output ports must be honoured by the engine.
 *
 * `FlowRunner::activatedPorts` resolved kind ports through
 * `NodeKindRegistry::default()` — the shared static catalogue — rather than
 * through the registry the host supplied to its `ExecutorRegistry`. So a kind
 * registered by the application was invisible at exactly the moment its ports
 * mattered, and the node fell through to publishing a single `out`.
 *
 * ## Why this is worse than "some ports are missing"
 *
 * `collectInputs` binds a payload only when `"<sourceId>:<handle>"` exists. An
 * edge leaving `matched` therefore finds nothing and delivers nothing — it does
 * not fail, and it does not warn. The downstream template is then COMPLETELY
 * CORRECT and renders empty, because the payload never arrived to have a field
 * in it.
 *
 * The consumer who reported it misdiagnosed two filed issues off the back of
 * exactly that, and an agent "fixed" one by correcting a field name that was
 * never wrong.
 *
 * ## It also broke the guarantee this package exists to uphold
 *
 * The TS side resolves ports through the registry a host registers into. So the
 * same graph JSON routed correctly on Node and collapsed to `out` on PHP — the
 * same-JSON-same-outputs guarantee, broken for precisely the hosts that extend
 * the kit. `ExecutorRegistry` already carried the registry; the engine simply
 * was not asking it.
 *
 * Reported by MOIC.
 */
function portsOf($result, string $nodeId): array
{
    // One `node-output` event PER PORT, each carrying `portId` -- there is no
    // `ports` array on the event. My first draft read a property that does not
    // exist, so it "failed" for a reason unrelated to the bug. Its own small
    // lesson: confirm a failing test fails for the REASON you think it does.
    $ports = [];
    foreach ($result->events as $event) {
        if ($event->type === 'node-output' && $event->nodeId === $nodeId) {
            $ports[] = $event->portId;
        }
    }

    return $ports;
}

function hostKinds(): NodeKindRegistry
{
    $kinds = new NodeKindRegistry();

    $kinds->register(new NodeKind(
        name: 'deal_list',
        category: 'data',
        label: 'Deal List',
        outputs: [new PortDescriptor('matched'), new PortDescriptor('empty')],
    ));

    return $kinds;
}

it('publishes a host kind DECLARED ports, not a single fallback out', function () {
    $graph = ffGraph([ffNode('d', 'deal_list')], []);

    $executors = (new ExecutorRegistry(kinds: hostKinds()))
        ->bind('deal_list', fn (ExecutionContext $c) => ['deals' => [1, 2], 'count' => 2]);

    $result = (new FlowRunner())->run($graph, $executors);

    expect(portsOf($result, 'd'))->toBe(['matched', 'empty']);
});

it('DELIVERS through an edge that leaves a host kind port', function () {
    // The failure with its consequence attached: without the fix the payload is
    // published only at `d:out`, the edge leaving `matched` binds nothing, and
    // `n` runs with empty inputs while the run reports success.
    $graph = ffGraph(
        [ffNode('d', 'deal_list'), ffNode('n', 'sink')],
        [ffEdge('e1', 'd', 'n', sourceHandle: 'matched')],
    );

    $seen = null;
    $executors = (new ExecutorRegistry(kinds: hostKinds()))
        ->bind('deal_list', fn (ExecutionContext $c) => ['deals' => [1, 2], 'count' => 2])
        ->bind('sink', function (ExecutionContext $c) use (&$seen) {
            $seen = $c->inputs;

            return 'ok';
        });

    $result = (new FlowRunner())->run($graph, $executors);

    expect($result->ok)->toBeTrue();

    // Keyed by the TARGET handle (`in` by default), not the source handle --
    // `matched` selects WHICH payload travels, `in` names where it lands. It is
    // ALSO keyed by the source node id, which is what makes `{{ d.count }}`
    // resolve as well as `{{ in.count }}`.
    //
    // The point of the assertion is that it lands at ALL: before the fix
    // `$seen` was an empty array and the run still reported ok.
    expect($seen['in'])->toBe(['deals' => [1, 2], 'count' => 2]);
    expect($seen['d'])->toBe(['deals' => [1, 2], 'count' => 2]);
});

it('the SHARED default registry actually carries the builtins', function () {
    // Not a restatement of the host-kind fix -- a SEPARATE defect it uncovered.
    //
    // `NodeKindRegistry::default()` was `self::$default ??= new self()`: an
    // EMPTY singleton that nothing ever populated. So the kind-ports fallback
    // in `activatedPorts` -- added, in its own words, so a branch node would
    // not "collapse to a single `out` here while routing correctly on Node" --
    // could never resolve a kind. The guarantee it was written to uphold was
    // broken by the lookup it used to uphold it.
    //
    // Wired to nothing: present, reviewed, commented, and with no effect. The
    // TypeScript twin had the identical defect, fixed the same day with
    // `ensureBuiltinKinds()`; nobody checked the PHP side.
    $default = NodeKindRegistry::default();

    expect($default->get('branch'))->not->toBeNull();
    expect(array_map(
        static fn (PortDescriptor $p) => $p->id,
        $default->get('branch')->outputs ?? [],
    ))->toBe(['true', 'false']);
});

it('routes a BUILTIN branch node by its kind ports with no host registry at all', function () {
    // The case the dead fallback was written for, now actually exercised.
    $graph = ffGraph([ffNode('b', 'branch')], []);

    $executors = (new ExecutorRegistry())
        ->bind('branch', fn (ExecutionContext $c) => Port::branch('true', ['v' => 1]));

    $result = (new FlowRunner())->run($graph, $executors);

    expect(portsOf($result, 'b'))->toBe(['true']);
});

it('still falls back to out for a kind nobody has registered', function () {
    // Widening the lookup must not change what happens when the lookup still
    // finds nothing.
    $graph = ffGraph([ffNode('u', 'not_a_registered_kind')], []);

    $executors = (new ExecutorRegistry(kinds: hostKinds()))
        ->bind('not_a_registered_kind', fn (ExecutionContext $c) => 'v');

    $result = (new FlowRunner())->run($graph, $executors);

    expect(portsOf($result, 'u'))->toBe(['out']);
});

it('a node OWN declared outputs still win over the kind', function () {
    // Precedence is unchanged: the document is more specific than the kind.
    $graph = ffGraph([ffNode('d', 'deal_list', outputs: ['only'])], []);

    $executors = (new ExecutorRegistry(kinds: hostKinds()))
        ->bind('deal_list', fn (ExecutionContext $c) => 'v');

    $result = (new FlowRunner())->run($graph, $executors);

    expect(portsOf($result, 'd'))->toBe(['only']);
});

it('routes host ports on a RESUMED node too, not only a freshly executed one', function () {
    // `publish()` is shared by execution and resume, and the durable driver
    // reads its ports from these same events -- so a resume that routed
    // differently would desynchronise the frontier from the engine.
    $graph = ffGraph(
        [ffNode('d', 'deal_list'), ffNode('n', 'sink')],
        [ffEdge('e1', 'd', 'n', sourceHandle: 'matched')],
    );

    $executors = (new ExecutorRegistry(kinds: hostKinds()))
        ->bind('deal_list', fn (ExecutionContext $c) => 'never runs')
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $result = (new FlowRunner())->run(
        $graph,
        $executors,
        options: new RunOptions(resumeOutputs: ['d' => ['deals' => [], 'count' => 0]]),
    );

    expect(portsOf($result, 'd'))->toBe(['matched', 'empty']);
});
