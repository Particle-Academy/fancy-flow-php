<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Nodes;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * The durable `user_input` executor, bound in place of the framework-free
 * default while a run executes inside {@see \FancyFlow\Laravel\Jobs\RunWorkflowJob}.
 *
 * The job injects the run's recorded submission as a `values` input. Absent a
 * submission, this aborts with a well-known reason so the job can PAUSE the run
 * (persist state, mark it awaiting-input) instead of passing empty values
 * through — the mid-run human form. On resume the submitted values are present
 * and the node emits them on `out`.
 *
 * Mirrors {@see DurableApprovalExecutor}, but resumes with a typed values
 * payload rather than a bool decision. Record one with
 * {@see \FancyFlow\Laravel\Models\WorkflowRun::submitInput()}.
 */
final class DurableUserInputExecutor implements NodeExecutor
{
    public const PAUSE_PREFIX = 'awaiting-input:';

    public function execute(ExecutionContext $ctx): mixed
    {
        // Strict null check, not truthiness: an empty form ([]) is a real
        // submission and must resume, not pause again.
        $values = $ctx->inputs['values'] ?? null;

        if ($values === null) {
            $ctx->abort(self::PAUSE_PREFIX.$ctx->node->id);
        }

        return $values;
    }
}
