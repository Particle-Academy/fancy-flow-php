<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\ToolInvoker;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `tool_use` — hand control to a host-registered tool by name via a
 * {@see ToolInvoker}. The `args` config is resolved through {@see Expr} against
 * the node's inputs.
 */
final class ToolUseExecutor implements NodeExecutor
{
    public function __construct(private readonly ToolInvoker $tools) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $tool = (string) $ctx->option('tool', '');
        $args = Expr::evaluate($ctx->option('args', []), $ctx->inputs);
        $args = is_array($args) ? $args : ['value' => $args];

        return $this->tools->invoke($tool, $args);
    }
}
