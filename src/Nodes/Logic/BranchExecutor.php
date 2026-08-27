<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\RoutingDiagnostics;
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
        $condition = $ctx->option('condition');
        $resolved = Expr::evaluate($condition, $ctx->inputs);
        $port = Expr::truthy($resolved) ? 'true' : 'false';

        // A condition that did not RESOLVE is falsy, so the run takes `false`
        // silently and for the wrong reason. Routing is unchanged; the reason is
        // now visible.
        RoutingDiagnostics::warnIfUnresolved($ctx, $condition, $port);

        return Port::branch($port, $ctx->input('in', $ctx->inputs));
    }
}
