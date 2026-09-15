<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

/**
 * The run-diagnostics table, run against THIS side.
 *
 * Two run-time warnings for a graph that runs, reports success and delivers
 * nothing down one path: an edge naming a port its completed source can never
 * publish (`UndeliveredEdgeTest`), and a `branch`/`switch_case` that routed on a
 * whole `{{ path }}` which did not resolve (`RoutingDiagnosticsTest`).
 *
 * The goldens were produced HERE: this runtime was the only one emitting either
 * warning when the table was written, and fancy-flow#17 is the other three
 * catching up to it. So a failure on this side is not "PHP drifted from the
 * others" -- it is this runtime's own behaviour changing under a table that
 * describes it, which is worth stopping for.
 *
 * The rows that carry the weight:
 *
 *  - `0010` -- the flabs smart-routing graph with an inverted `cases` map, the
 *    one PHP warned about and three engines passed silently.
 *  - `0009`, `0011`, `0014` -- the silent rows. An untaken branch port, a port
 *    that exists only through the node's own `cases`, and an edge out of a node
 *    that never ran must never warn: a warning that fires on ordinary branching
 *    is how a real warning stops being read.
 *  - `0003` -- null is a RESOLVED value; only an absent path warns.
 */

/**
 * Run one case and report every warn-level log event, sorted by message.
 *
 * Sorted because runtimes may break topological ties between same-depth nodes
 * differently, which would reorder two warnings without either being wrong;
 * ordering is `flow/graph-runs`' question.
 *
 * @param  array<string,mixed>  $case
 * @return list<array{nodeId: ?string, message: string, detail: mixed}>
 */
function runDiagnosticsCase(array $case): array
{
    // Fully specified, matching the sibling harnesses: a LOCAL registry with the
    // structural kinds, a lenient import, and the built-in offline executors.
    $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
    $import = Workflow::import($case['input']['schema'], lenient: true, registry: $registry);

    $result = (new FlowRunner())->run(
        $import->graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: $case['input']['initialInputs'] ?? []),
    );

    $warnings = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $warnings[] = ['nodeId' => $event->nodeId, 'message' => $event->message, 'detail' => $event->detail];
        }
    }

    usort($warnings, static fn (array $a, array $b): int => strcmp($a['message'], $b['message']));

    return $warnings;
}

it('matches the flow/run-diagnostics table on every case', function (): void {
    $summary = Conformance::runTable('flow/run-diagnostics', runDiagnosticsCase(...));

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

    // The vacuity floor, just under the fourteen rows. A suite that skipped
    // everything reports zero failures too.
    expect($summary['passed'])->toBeGreaterThan(12);
});
