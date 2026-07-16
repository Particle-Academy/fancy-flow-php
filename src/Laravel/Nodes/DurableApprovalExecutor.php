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
 * decision, this aborts with a well-known reason so the job can PAUSE the run
 * (persist state, mark it awaiting-approval) instead of failing it — the
 * trust-but-verify staged-write pause. On resume the decision is present and the
 * node routes to `approved` / `denied`.
 */
final class DurableApprovalExecutor implements NodeExecutor
{
    public const PAUSE_PREFIX = 'awaiting-approval:';

    public function execute(ExecutionContext $ctx): mixed
    {
        $decision = $ctx->inputs['approved'] ?? null;

        if ($decision === null) {
            $ctx->abort(self::PAUSE_PREFIX.$ctx->node->id);
        }

        return Port::branch($decision ? 'approved' : 'denied', $ctx->input('in', $ctx->inputs));
    }
}
