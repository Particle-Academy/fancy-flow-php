<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Output;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `log` — emit the resolved `message` to the run feed at the configured level.
 * The message is resolved through {@see Expr} against the node's inputs.
 */
final class LogExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $level = (string) $ctx->option('level', 'info');
        $message = Expr::text(Expr::evaluate($ctx->option('message', ''), $ctx->inputs));

        $ctx->emit(RunEvent::log($level, $message, $ctx->node->id));

        return ['logged' => $message, 'level' => $level];
    }
}
