<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * A cooperative cancellation flag, the PHP analogue of the DOM `AbortSignal`
 * the TS engine accepts. The runner checks {@see aborted()} before each node.
 *
 * PHP runs a flow synchronously, so a signal is flipped either from within an
 * executor (via the shared controller) or by a host wrapping the run — there
 * is no background thread. Pair it with {@see AbortController}.
 */
final class AbortSignal
{
    public function __construct(private bool $aborted = false, public ?string $reason = null) {}

    public function aborted(): bool
    {
        return $this->aborted;
    }

    /** @internal Flipped by {@see AbortController::abort()}. */
    public function markAborted(?string $reason = null): void
    {
        $this->aborted = true;
        $this->reason = $reason;
    }
}
