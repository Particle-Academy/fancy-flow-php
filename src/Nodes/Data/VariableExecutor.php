<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Data;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `variable` — a workflow-scoped value. Resolves `value` through {@see Expr}
 * against the node's inputs and emits it, so downstream nodes read the value
 * directly. The variable's `name` is available on the node config.
 */
final class VariableExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return Expr::evaluate($ctx->option('value'), $ctx->inputs);
    }
}
