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
    public static function executors(FancyFlowManager $flow): ExecutorRegistry
    {
        return $flow->executors()->fork()
            ->bind('human_approval', DurableApprovalExecutor::class)
            ->bind('user_input', DurableUserInputExecutor::class);
    }
}
