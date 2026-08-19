<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/**
 * A run needs something from a person, and this is the moment to go and ask.
 *
 * ## Why this exists separately from WorkflowSettled
 *
 * `WorkflowSettled` reports that an ATTEMPT ended and, among its outcomes, that
 * it ended awaiting a human. It is the teardown hook. It does not say WHICH
 * node is waiting or WHAT it is asking for, so a host wanting to send the email
 * had to re-query the run and re-derive the form — which is exactly the kind of
 * "obvious next step" that goes unbuilt, leaving a run parked forever because
 * nobody was ever told.
 *
 * This is the request itself, carrying everything a notifier needs:
 *
 *     Event::listen(HumanInputRequested::class, function ($e) {
 *         Mail::to($approver)->queue(new ApprovalNeeded($e->runId, $e->nodeId, $e->detail));
 *     });
 *
 * ## The step-wise contract this completes
 *
 * The job that reaches a human gate does its work by FIRING THE REQUEST and
 * then finishing. It records the pause, dispatches this, and returns. No
 * worker, connection or process is held while a person — who may not be logged
 * in, or anywhere near the interface — takes days to answer.
 *
 * When they do answer, `WorkflowRun::submitInput()` / `approve()` records it and
 * enqueues the continuation. The inbound response is what starts the next job;
 * nothing on the server was waiting for it.
 *
 * Dispatched by BOTH queue drivers at the moment the pause is checkpointed, so
 * a host's notification behaves identically under `single` and `per_node`.
 */
final class HumanInputRequested
{
    public function __construct(
        public readonly string $runId,
        /** The node that paused — where the answer gets recorded. */
        public readonly string $nodeId,
        /** `approval`, `input`, or a marketplace kind's own wait. */
        public readonly string $awaiting,
        /**
         * Kind-supplied context for whoever renders the wait — the form schema,
         * the question, the diff to approve. JSON-serializable: it has already
         * crossed a queue boundary and a database column to get here.
         */
        public readonly mixed $detail = null,
    ) {}

    public function isApproval(): bool
    {
        return $this->awaiting === 'approval';
    }

    public function isInput(): bool
    {
        return $this->awaiting === 'input';
    }
}
