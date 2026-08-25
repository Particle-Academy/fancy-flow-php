<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

/**
 * The entry-points table, run against THIS side.
 *
 * A graph may hold more than one trigger, and a trigger has no inbound edges —
 * which IS the readiness rule — so every trigger's branch ran on every run,
 * whichever one fired. The triggers themselves are harmless; everything
 * downstream of the ones that did not fire is not.
 *
 * This table was written BEFORE any runtime implemented the rule, so it is a
 * specification rather than a post-mortem. `@particle-academy/fancy-flow` and
 * `fancy-flow` (Python) satisfy the identical rows.
 *
 * The rows that carry the weight here:
 *
 *  - `0101` — unset must behave EXACTLY as before. This is the compatibility
 *    guarantee, and breaking it would break every multi-trigger graph in the
 *    field in order to fix one.
 *  - `0104` — a node fed by both branches must still run when one fires. An
 *    implementation requiring ALL inbound edges active passes `0102` and
 *    silently stops every graph that converges — which is the shape consumers
 *    were told to use as the workaround for this very bug.
 *  - `0106` / `0107` — empty is not unset, and naming a non-entry node selects
 *    no entry at all.
 */

/**
 * Run one case: import leniently, run with the offline executors, and report
 * WHICH nodes executed.
 *
 * A node that ran has an entry in `outputs`; one that was skipped does not. The
 * ids are sorted because this suite asks which nodes ran, not in what order —
 * `flow/graph-runs` already pins ordering-sensitive behaviour.
 *
 * @param  array<string,mixed>  $case
 * @return list<string>
 */
function runEntryPointsCase(array $case): array
{
    // The run is fully specified rather than left to the runner, matching the
    // sibling parity harness: a LOCAL registry with the structural kinds, a
    // lenient import, and the built-in offline executors. A runner that
    // populates the SHARED registry instead gets different declared output
    // ports and disagrees on rows nobody changed.
    $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
    $import = Workflow::import($case['input']['schema'], lenient: true, registry: $registry);

    $result = (new FlowRunner())->run(
        $import->graph,
        Builtin::executors(),
        options: new RunOptions(
            initialInputs: $case['input']['initialInputs'] ?? [],
            entryNodes: $case['input']['entryNodes'],
        ),
    );

    $ran = array_keys($result->outputs);
    sort($ran);

    return $ran;
}

it('matches the flow/entry-points table on every case', function (): void {
    $summary = Conformance::runTable('flow/entry-points', runEntryPointsCase(...));

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

    // The vacuity floor, just under the seven rows. A suite that skipped
    // everything reports zero failures too.
    expect($summary['passed'])->toBeGreaterThan(5);
});
