<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Nodes\DurableApprovalExecutor;
use FancyFlow\Laravel\Nodes\DurableUserInputExecutor;

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
