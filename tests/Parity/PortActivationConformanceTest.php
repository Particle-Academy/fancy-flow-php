<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;
use ParticleAcademy\Conformance\Conformance;

/**
 * The port-activation table, run against THIS side.
 *
 * Until #18 the engine knew two answers: `__port` / `branch` lit exactly one
 * port, and anything else lit EVERY declared port. There was no way to say
 * "these two of five", so a router that matched two lanes had to drop the rest
 * of the work or wake lanes nobody asked for. `__ports` is the third answer,
 * and this table is what keeps all four runtimes giving it identically.
 *
 * The rows assert the `node-output` EVENTS, not `activatedPorts`' return value.
 * That method is private in every runtime, and the events are what a consumer —
 * and the durable layer, which reads activated ports straight off them —
 * actually observes. Asserting the private method would also let this file pass
 * while the events it feeds were wrong.
 *
 * Row 0303 is skipped for NODE, not here. An explicitly empty `outputs` means
 * "this node has no output ports" and publishes nothing in this runtime, in
 * Python and in Rust; `@particle-academy/fancy-flow` collapses it to `out`
 * because its fallback tests a falsy length. The summary prints that skip on
 * every run, which is the only way a known disagreement stays visible.
 */

/**
 * Run a one-node graph and report what the node published, in order.
 *
 * The kind is `hostRouter`, which no runtime ships, and no registry is handed
 * to the runner. Both are deliberate: the declared-port fallback reaches for
 * the KIND's ports before falling back to `out`, so naming a builtin would
 * quietly assert the builtin's ports instead of the rule under test.
 *
 * @param  array<string,mixed>  $case
 * @return list<array{port: string, value: mixed}>
 */
function portActivationCase(array $case): array
{
    $declared = $case['input']['declaredOutputs'];
    $result = $case['input']['result'];

    $node = new FlowNode(
        id: 'r',
        type: 'hostRouter',
        outputs: $declared === null
            ? null
            : array_map(static fn (string $id): PortDescriptor => new PortDescriptor($id), $declared),
    );

    $executors = (new ExecutorRegistry())->bind('hostRouter', static fn (): mixed => $result);

    $run = (new FlowRunner())->run(new FlowGraph([$node], []), $executors);

    // Emission order, never sorted: a map-shaped `__ports` lights its ports in
    // the order the map declares them, and row 0105 is only an assertion at all
    // because this list stays in the order the engine produced it.
    $lit = [];
    foreach ($run->events as $event) {
        if ($event->type === 'node-output' && $event->nodeId === 'r') {
            $lit[] = ['port' => $event->portId, 'value' => $event->value];
        }
    }

    return $lit;
}

it('matches the flow/port-activation table on every case', function (): void {
    $summary = Conformance::runTable('flow/port-activation', portActivationCase(...));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail',
    );

    expect($failures)->toBe([], 'PHP disagrees with the shared table on: '.implode(
        ', ',
        array_column($failures, 'id'),
    ));

    expect($summary['failed'])->toBe(0);

    // The vacuity floor, just under the twelve rows. One row expects NO ports
    // at all, so a table that loaded nothing has no failures either and would
    // read as green without this.
    expect($summary['passed'])->toBeGreaterThan(10);
});
