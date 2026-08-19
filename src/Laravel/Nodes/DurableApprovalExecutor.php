<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Nodes;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;

/**
 * The durable `human_approval` executor, bound in place of the framework-free
 * default while a run executes inside {@see \FancyFlow\Laravel\Jobs\RunWorkflowJob}.
 *
 * The job injects the run's recorded decision as an `approved` input. Absent a
 * decision, this pauses the run through the public pause contract
 * ({@see \FancyFlow\Runtime\Pause}) instead of failing it — the
 * trust-but-verify staged-write pause. On resume the decision is present and the
 * node routes to `approved` / `denied`.
 */
final class DurableApprovalExecutor implements NodeExecutor
{
    /**
     * @deprecated Superseded by the public pause contract. Kept because it is
     * written into the `error` column of every run that parked under an older
     * version, and {@see \FancyFlow\Runtime\Pause::decode()} still reads it.
     */
    public const PAUSE_PREFIX = 'awaiting-approval:';

    /**
     * @param  array<string,bool>  $approvals  Decisions this run has actually
     *   recorded, keyed by node id. Membership — not the presence of data on the
     *   `approved` port — is what decides whether a person has ruled on it.
     */
    public function __construct(private readonly array $approvals = []) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        // An approval gate pauses because it IS an approval gate. See the note
        // in DurableUserInputExecutor: reading the decision off the input port
        // meant anything that pre-filled that port — initial inputs, an upstream
        // edge, or a decision recorded before the node ran — counted as a human
        // having approved, and the run sailed through the gate.
        $nodeId = $ctx->node->id;

        if (array_key_exists($nodeId, $this->approvals)) {
            $decision = $this->approvals[$nodeId];
        } else {
            // Opt-in, off by default — see the note in DurableUserInputExecutor.
            // Worth weighing harder here than for a form: this one lets an
            // upstream node approve on a person's behalf, so turning it on means
            // the graph, not a human, is the approver.
            $prefilled = $ctx->inputs['approved'] ?? null;

            if ($ctx->option('autoAnswerFromInput', false) === true && $prefilled !== null) {
                $decision = $prefilled;
            } else {
                // Carry what the request is ABOUT, not just that there is one.
                // A host listening for HumanInputRequested has to put something
                // in the email, and re-deriving the node's title from the graph
                // is the kind of obvious-next-step that goes unbuilt — leaving a
                // run parked forever because nobody was ever told what for.
                // Matches the TypeScript and Python twins, which have always
                // passed a detail here.
                $ctx->pauseForHuman('approval', array_filter([
                    'title' => $ctx->option('title', 'Approve action'),
                    'description' => $ctx->option('description'),
                ], static fn ($v) => $v !== null));
            }
        }

        return Port::branch($decision ? 'approved' : 'denied', $ctx->input('in', $ctx->inputs));
    }
}
