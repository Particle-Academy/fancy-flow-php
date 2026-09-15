<?php

declare(strict_types=1);

use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Laravel\Runs\DispatchLimit;
use FancyFlow\Laravel\Runs\Frontier;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

/**
 * The durable-dispatch table, run against THIS side's own frontier and budget.
 *
 * fancy-flow-php#17 made serial the default: a node is handed to the queue only
 * once the node before it has settled, in declaration order, and a paused gate
 * keeps its slot. This runtime produced the goldens, so a failure here is its
 * own behaviour changing under a table that describes it.
 *
 * The simulation is the manifest's, step for step. It exercises the SAME two
 * functions AdvanceWorkflowJob calls -- `Frontier::compute` and
 * `DispatchLimit::select` -- without a queue, a database or an engine, which is
 * what lets the TS and Python coordinators answer the identical question.
 *
 * The rows that carry the weight:
 *
 *  - `0001` -- the default. It dispatched the whole frontier before 0.54.0.
 *  - `0007` -- declaration order among what is ready NOW; breadth-first fails it.
 *  - `0008` / `0010` -- a paused gate holds its slot, alone and under a cap.
 *  - `0014` -- a cap is measured against held work, not the size of one batch.
 *
 * @param  array<string,mixed>  $case
 * @return array{trace: list<string>, neverDispatched: list<string>}
 */
function durableDispatchCase(array $case): array
{
    $input = $case['input'];
    $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
    $graph = Workflow::import($input['schema'], lenient: true, registry: $registry)->graph;
    $limit = $input['maxConcurrent'] === DispatchLimit::UNLIMITED ? null : $input['maxConcurrent'];
    $pauses = $input['pauses'] ?? [];
    $publishes = (array) ($input['publishes'] ?? []);

    $state = [];
    $inFlight = [];
    $trace = [];

    for ($step = 0; $step < 1000; $step++) {
        $frontier = Frontier::compute($graph, $state);
        foreach ($frontier['skipped'] as $id) {
            $state[$id] = ['status' => WorkflowRunNode::SKIPPED, 'ports' => []];
            $trace[] = "skip {$id}";
        }

        foreach (DispatchLimit::select($frontier['ready'], $state, $limit) as $id) {
            $state[$id] = ['status' => WorkflowRunNode::CLAIMED, 'ports' => []];
            $inFlight[] = $id;
            $trace[] = "dispatch {$id}";
        }

        if ($inFlight === []) {
            break;
        }

        $id = array_shift($inFlight);
        if (in_array($id, $pauses, true)) {
            $state[$id] = ['status' => WorkflowRunNode::PAUSED, 'ports' => []];
            $trace[] = "pause {$id}";
        } else {
            $state[$id] = ['status' => WorkflowRunNode::COMPLETED, 'ports' => $publishes[$id] ?? ['out']];
            $trace[] = "complete {$id}";
        }
    }

    $neverDispatched = [];
    foreach ($graph->nodes as $node) {
        if (! isset($state[$node->id])) {
            $neverDispatched[] = $node->id;
        }
    }

    return ['trace' => $trace, 'neverDispatched' => $neverDispatched];
}

it('matches the flow/durable-dispatch table on every case', function (): void {
    $summary = Conformance::runTable('flow/durable-dispatch', durableDispatchCase(...));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail',
    );

    expect($failures)->toBe([]);
    expect($summary['passed'] ?? 0)->toBe(14);
});
