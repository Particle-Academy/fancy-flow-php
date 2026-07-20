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

    public function execute(ExecutionContext $ctx): mixed
    {
        $decision = $ctx->inputs['approved'] ?? null;

        if ($decision === null) {
            $ctx->pauseForHuman('approval');
        }

        return Port::branch($decision ? 'approved' : 'denied', $ctx->input('in', $ctx->inputs));
    }
}
