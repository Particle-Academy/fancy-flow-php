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

    /**
     * @param  array<string,array<string,mixed>>  $submissions  Answers this run has
     *   actually recorded, keyed by node id. Membership — not the presence of
     *   data on the `values` port — is what decides whether the node has been
     *   answered.
     */
    public function __construct(private readonly array $submissions = []) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        // A human node pauses because it IS a human node, not because its input
        // port happens to be empty.
        //
        // This used to read `$ctx->inputs['values']` and pause only when that
        // was null, which conflated two different questions: "has a person
        // answered this?" and "is there data on the values port?". Anything that
        // pre-filled the port — initial inputs, an upstream edge, or a
        // submission recorded before the node ever ran — answered the second
        // question and silently skipped the gate. On the per_node driver a host
        // frontend posting an empty submit could win that race against the
        // queued run, so the step a person was meant to see never appeared and
        // downstream nodes ran on empty input.
        //
        // Now the only thing that resumes this node is a recorded submission for
        // THIS node. Pre-filled inputs never satisfy it.
        $nodeId = $ctx->node->id;

        if (! array_key_exists($nodeId, $this->submissions)) {
            // Opt-in escape hatch, off by default: let a value already on the
            // `values` port stand in for the person. This is the old behaviour,
            // and it is genuinely wanted for a step that is a human form when a
            // human is present and a pass-through when an upstream node already
            // computed the answer.
            //
            // It is a config flag rather than the default because it cannot be
            // told apart from the failure it used to cause. Naming it puts the
            // decision in the graph, where it is reviewable, instead of leaving
            // it as an emergent property of whatever happened to write to a port.
            $prefilled = $ctx->inputs['values'] ?? null;

            if ($ctx->option('autoAnswerFromInput', false) === true && $prefilled !== null) {
                return $prefilled;
            }

            $ctx->pauseForHuman('input', $this->formDetail($ctx));
        }

        // An empty form ([]) is a real answer — it resumes rather than pausing
        // again. That distinction survives because it is now carried by whether
        // the key exists, not by whether the value is null.
        return $this->submissions[$nodeId];
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
