<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/**
 * Dispatched whenever an in-process run stops, on EVERY exit path — completion,
 * failure, a pause awaiting a human, or an uncaught throw.
 *
 * `WorkflowStarted` has always fired when a durable run begins, but the durable
 * job emitted a terminal event only on success. Anything a host bound for the
 * duration of a run — an ambient run context, a listener, a log scope — was
 * therefore never torn down when the run paused, failed, or died, and leaked on
 * the queue worker into whatever job ran next (#2).
 *
 * This is the guaranteed counterpart to `WorkflowStarted`: exactly one is
 * dispatched per in-process attempt. It reports the outcome rather than
 * replacing the outcome events — `WorkflowFinished` / `WorkflowFailed` still
 * fire for completion and failure. Bind teardown to this, not to those.
 *
 * Note it is per ATTEMPT: a job that throws and is retried emits one settle per
 * attempt, each paired with its own `WorkflowStarted`.
 */
final class WorkflowSettled
{
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const AWAITING_INPUT = 'awaiting_input';
    /** The attempt ended by throwing — the queue may retry it. */
    public const ERRORED = 'errored';

    public function __construct(
        public readonly string $runId,
        /** One of the class constants above. */
        public readonly string $outcome,
        public readonly ?string $error = null,
    ) {}

    /** True when no further attempt will run for this outcome without new input. */
    public function isTerminal(): bool
    {
        return $this->outcome === self::COMPLETED || $this->outcome === self::FAILED;
    }

    /** True when the run stopped to wait on a human decision or submission. */
    public function isAwaitingHuman(): bool
    {
        return $this->outcome === self::AWAITING_APPROVAL || $this->outcome === self::AWAITING_INPUT;
    }
}
