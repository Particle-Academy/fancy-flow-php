<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Output;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `output` — a terminal node that captures the workflow's result. Returns its
 * incoming value so it lands in `RunResult::outputs[nodeId]`.
 */
final class OutputExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return $ctx->input('in', $ctx->inputs);
    }
}
