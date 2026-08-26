<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Schema\PortDescriptor;

/**
 * An edge that delivers NOTHING must say so.
 *
 * `collectInputs` binds a payload only when `"<sourceId>:<handle>"` exists, and
 * the miss was silent: no failure, no warning, and a downstream template that is
 * COMPLETELY CORRECT rendering empty, because the payload never arrived to have
 * a field in it.
 *
 * A consumer misdiagnosed two filed issues off the back of that, and an agent
 * "fixed" one by correcting a field name that was never wrong. It is also the
 * shape a 0.43.0 upgrade can produce on a `for_each` with a handle-less edge, so
 * the class matters more than either instance.
 *
 * The discipline this file exists to hold: **warn on misconfiguration, never on
 * an untaken branch.** A warning that fires on ordinary branching is noise, and
 * noise is how a real warning stops being read.
 */
function warnings($result): array
{
    $out = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $out[] = $event->message;
        }
    }

    return $out;
}

it('warns when an edge reads a port the source never publishes', function () {
    $graph = ffGraph(
        [ffNode('n2', 'plain'), ffNode('n3', 'sink')],
        [ffEdge('e2', 'n2', 'n3', sourceHandle: 'text')],
    );

    $executors = (new ExecutorRegistry())
        ->bind('plain', fn (ExecutionContext $c) => ['text' => 'hi'])
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $result = (new FlowRunner())->run($graph, $executors);

    $warnings = warnings($result);
    expect($warnings)->toHaveCount(1);

    // The agreed shape, in order: edge id, consequence in runtime terms, the
    // ports that DO exist, and the remedy for the common case.
    expect($warnings[0])->toContain('Edge e2');
    expect($warnings[0])->toContain('"text"');
    expect($warnings[0])->toContain('node n2');
    expect($warnings[0])->toContain('nothing would reach n3');
    expect($warnings[0])->toContain('Available: out');
    expect($warnings[0])->toContain('Leave sourceHandle off');
});

it('does NOT warn for a branch that simply was not taken', function () {
    // The discipline. `false` never publishes, so the edge leaving it binds
    // nothing -- and that is ORDINARY BRANCHING, not a misconfiguration. Keyed
    // on the source having COMPLETED is what separates the two, and getting
    // this wrong would make every branching graph noisy.
    $graph = ffGraph(
        [ffNode('b', 'branch'), ffNode('yes', 'sink'), ffNode('no', 'sink')],
        [
            ffEdge('e1', 'b', 'yes', sourceHandle: 'true'),
            ffEdge('e2', 'b', 'no', sourceHandle: 'false'),
        ],
    );

    $executors = (new ExecutorRegistry())
        ->bind('branch', fn (ExecutionContext $c) => Port::branch('true', ['v' => 1]))
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $result = (new FlowRunner())->run($graph, $executors);

    expect(warnings($result))->toBe([]);
});

it('does not warn when the edge delivers normally', function () {
    $graph = ffGraph(
        [ffNode('a', 'plain'), ffNode('b', 'sink')],
        [ffEdge('e1', 'a', 'b')],
    );

    $executors = (new ExecutorRegistry())
        ->bind('plain', fn (ExecutionContext $c) => ['x' => 1])
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    expect(warnings((new FlowRunner())->run($graph, $executors)))->toBe([]);
});

it('names a near-miss when the handle is a FIELD the node emits', function () {
    // The part only the engine can supply, and the actual confusion: an agent
    // reaching for a field name where a port belongs. Naming it turns a
    // correction into an explanation.
    $kinds = new NodeKindRegistry();
    $kinds->register(new NodeKind(
        name: 'summariser',
        category: 'ai',
        label: 'Summariser',
        outputShape: [['path' => 'text', 'type' => 'string']],
    ));

    $graph = ffGraph(
        [ffNode('n2', 'summariser'), ffNode('n3', 'sink')],
        [ffEdge('e2', 'n2', 'n3', sourceHandle: 'text')],
    );

    $executors = (new ExecutorRegistry(kinds: $kinds))
        ->bind('summariser', fn (ExecutionContext $c) => ['text' => 'hi'])
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $warnings = warnings((new FlowRunner())->run($graph, $executors));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('is a FIELD this node emits, not a port');
    expect($warnings[0])->toContain('{{ in.text }}');
});

it('warns on a HANDLE-LESS edge leaving a node that publishes named ports only', function () {
    // The 0.43.0 upgrade hazard, made loud. `for_each` declares `item`/`done`
    // and publishes no `out`, so a handle-less edge -- which reads `out` --
    // stops delivering. Before this warning that was silent; the consumer had
    // to enumerate their registry by hand to find which kinds were exposed.
    $kinds = new NodeKindRegistry();
    $kinds->register(new NodeKind(
        name: 'looper',
        category: 'logic',
        label: 'Looper',
        outputs: [new PortDescriptor('item'), new PortDescriptor('done')],
    ));

    $graph = ffGraph(
        [ffNode('f', 'looper'), ffNode('n', 'sink')],
        [ffEdge('e1', 'f', 'n')],
    );

    $executors = (new ExecutorRegistry(kinds: $kinds))
        ->bind('looper', fn (ExecutionContext $c) => ['items' => [], 'count' => 0])
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $warnings = warnings((new FlowRunner())->run($graph, $executors));

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('"out"');
    expect($warnings[0])->toContain('Available: item, done');
    // No "leave sourceHandle off" here -- there is no handle to remove, and
    // suggesting it would be advice that cannot be followed.
    expect($warnings[0])->not->toContain('Leave sourceHandle off');
});

it('carries the edge and handle as structured detail, not only prose', function () {
    // A host wiring this into its own diagnostics should not have to parse the
    // sentence back apart.
    $graph = ffGraph(
        [ffNode('n2', 'plain'), ffNode('n3', 'sink')],
        [ffEdge('e2', 'n2', 'n3', sourceHandle: 'text')],
    );

    $executors = (new ExecutorRegistry())
        ->bind('plain', fn (ExecutionContext $c) => ['text' => 'hi'])
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $detail = null;
    foreach ((new FlowRunner())->run($graph, $executors)->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $detail = $event->detail;
        }
    }

    expect($detail)->toBe(['edge' => 'e2', 'source' => 'n2', 'sourceHandle' => 'text']);
});
