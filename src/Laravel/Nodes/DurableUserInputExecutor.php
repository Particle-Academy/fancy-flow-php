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
 * submission, this pauses the run through the public pause contract
 * ({@see \FancyFlow\Runtime\Pause}) instead of passing empty values through —
 * the mid-run human form. On resume the submitted values are present and the
 * node emits them on `out`.
 *
 * Mirrors {@see DurableApprovalExecutor}, but resumes with a typed values
 * payload rather than a bool decision. Record one with
 * {@see \FancyFlow\Laravel\Models\WorkflowRun::submitInput()}.
 */
final class DurableUserInputExecutor implements NodeExecutor
{
    /**
     * @deprecated Superseded by the public pause contract. Kept because it is
     * written into the `error` column of every run that parked under an older
     * version, and {@see \FancyFlow\Runtime\Pause::decode()} still reads it.
     */
    public const PAUSE_PREFIX = 'awaiting-input:';

    public function execute(ExecutionContext $ctx): mixed
    {
        // Strict null check, not truthiness: an empty form ([]) is a real
        // submission and must resume, not pause again.
        $values = $ctx->inputs['values'] ?? null;

        if ($values === null) {
            $ctx->pauseForHuman('input', $this->formDetail($ctx));
        }

        return $values;
    }

    /**
     * The form to render while parked, carried on the pause itself.
     *
     * `WorkflowRun::awaitingForm()` already reconstructs this from the stored
     * schema, but only for THIS kind. Putting it on the signal is what lets a
     * host render a third-party wait it knows nothing about.
     *
     * @return array<string,mixed>
     */
    private function formDetail(ExecutionContext $ctx): array
    {
        return [
            'title' => $ctx->option('title'),
            'fields' => $ctx->option('fields', []),
        ];
    }
}
