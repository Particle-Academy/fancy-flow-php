<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;

/**
 * `switch_case` — resolves `value` and routes to the port named by the matching
 * entry in `cases` (a map of value → portId), falling back to `default`. The
 * incoming value is carried down the chosen port.
 */
final class SwitchCaseExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $value = Expr::text(Expr::evaluate($ctx->option('value'), $ctx->inputs));
        $cases = $ctx->option('cases', []);
        $port = is_array($cases) && isset($cases[$value]) ? (string) $cases[$value] : 'default';

        return Port::only($port, $ctx->input('in', $ctx->inputs));
    }
}
