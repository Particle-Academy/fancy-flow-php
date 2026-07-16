<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Human;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;

/**
 * `human_approval` — routes to the `approved` or `denied` port. In a live host
 * the run pauses here (trust-but-verify staged write) until a human decides; the
 * framework-free default reads an `approved` flag from the incoming payload
 * (defaulting to approved) so flows are testable end-to-end. The 0.3 Laravel
 * layer adds the durable pause + `WorkflowRun::approve()` resume.
 */
final class HumanApprovalExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $decision = $ctx->inputs['approved'] ?? true;
        $approved = Expr::truthy($decision);

        return Port::branch($approved ? 'approved' : 'denied', $ctx->input('in', $ctx->inputs));
    }
}
