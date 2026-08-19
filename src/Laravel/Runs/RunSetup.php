<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Nodes\DurableApprovalExecutor;
use FancyFlow\Laravel\Nodes\DurableUserInputExecutor;
use FancyFlow\Runtime\RunIdentity;

/**
 * The two things every durable run needs before the engine sees it, in one
 * place so the two queue drivers cannot drift apart on them.
 *
 * Both are load-bearing for resume: a run parked on a human comes back with a
 * recorded decision, and unless that decision is fed in as an input the node
 * pauses again and the run never moves.
 */
final class RunSetup
{
    /**
     * The run's entry inputs, with recorded human answers folded in.
     *
     * An approval arrives as `approved`, a form submission as `values` — the
     * same shapes {@see DurableApprovalExecutor} and {@see DurableUserInputExecutor}
     * read, so resuming is just "the input is there this time".
     *
     * @return array<string,array<string,mixed>>
     */
    public static function initialInputs(WorkflowRun $run): array
    {
        // Answers are still merged onto the node's `approved` / `values` ports,
        // because that is the only resume channel a THIRD-PARTY pausing node
        // has: a marketplace kind that calls `pauseForHuman()` reads its answer
        // off its input port, and this package cannot reach inside its executor.
        //
        // What changed is that our own two human executors no longer consult
        // these ports at all — they resume on membership in the run's recorded
        // answers (see executors()). So this merge can no longer skip an
        // `human_approval` or `user_input` gate, which is what it used to do
        // whenever anything pre-filled the port: a host's own `initial_inputs`,
        // an upstream edge, or a submission recorded before the node ever ran.
        $initial = $run->initial_inputs ?? [];

        foreach ($run->approvals ?? [] as $nodeId => $approved) {
            $initial[$nodeId] = array_merge($initial[$nodeId] ?? [], ['approved' => $approved]);
        }

        foreach ($run->submissions ?? [] as $nodeId => $values) {
            $initial[$nodeId] = array_merge($initial[$nodeId] ?? [], ['values' => $values]);
        }

        return $initial;
    }

    /**
     * The identity handed to ONE node about to execute.
     *
     * `attempt` and `firstAttemptAt` come off that node's CLAIM ROW rather than
     * off the run, and the difference is the point: a node executing for the
     * first time on a run's third attempt is on ITS attempt 1, and a window
     * check that read the run's attempt would refuse a write nothing had ever
     * sent. Only the per-node driver can be exact here, because only it has a
     * per-node row.
     *
     * With no row yet (the claim is taken before this is called, so this is the
     * defensive branch) the run-scoped identity is returned unchanged.
     *
     * @param iterable<\FancyFlow\Laravel\Models\WorkflowRunNode> $rows
     */
    public static function identityFor(WorkflowRun $run, iterable $rows, string $nodeId): RunIdentity
    {
        foreach ($rows as $row) {
            if ($row->node_id === $nodeId) {
                return (new RunIdentity($run->run_key))
                    ->withAttempt($row->attempts, $row->firstAttemptAt());
            }
        }

        return new RunIdentity($run->run_key);
    }

    /**
     * The app's executors with the two pausing human nodes swapped in.
     *
     * A fork, not a mutation: the durable behaviour applies to this run only,
     * and the shared registry keeps serving synchronous `FancyFlow::run()` calls
     * that have no run row to park against.
     */
    public static function executors(FancyFlowManager $flow, ?WorkflowRun $run = null): ExecutorRegistry
    {
        // Instances, not class strings: each carries the answers THIS run has
        // recorded, which is what the executors now check instead of reading
        // their input ports. A null run means no answers yet, so every human
        // node pauses — the correct behaviour for a fresh run.
        return $flow->executors()->fork()
            ->bind('human_approval', new DurableApprovalExecutor($run?->approvals ?? []))
            ->bind('user_input', new DurableUserInputExecutor($run?->submissions ?? []));
    }
}
