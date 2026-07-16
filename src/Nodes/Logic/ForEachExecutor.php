<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `for_each` — resolves `source` to a list and surfaces it downstream. The
 * single-run engine has no loop construct, so 0.1 emits the resolved list plus a
 * count on both the `item` and `done` ports; genuine per-item fan-out is a 0.3
 * concurrency feature (queued sub-jobs). Hosts that need real iteration override
 * this executor.
 */
final class ForEachExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $source = Expr::evaluate($ctx->option('source'), $ctx->inputs);
        $items = is_array($source) ? array_values($source) : ($source === null ? [] : [$source]);

        return ['items' => $items, 'count' => count($items)];
    }
}
