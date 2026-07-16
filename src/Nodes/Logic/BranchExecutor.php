<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;

/**
 * `branch` — evaluates the `condition` and routes to the `true` or `false` port.
 * The condition is resolved through {@see Expr} against the node's inputs (e.g.
 * `{{ $json.active }}`); {@see Expr::truthy()} decides the branch. The incoming
 * value is carried down the taken branch.
 */
final class BranchExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $resolved = Expr::evaluate($ctx->option('condition'), $ctx->inputs);
        $port = Expr::truthy($resolved) ? 'true' : 'false';

        return Port::branch($port, $ctx->input('in', $ctx->inputs));
    }
}
