<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `transform` — reshape data with the `expression` config, resolved through
 * {@see Expr} against the node's inputs. With no expression, the `in` value
 * passes through unchanged. Hosts wanting a full expression language (e.g.
 * symfony/expression-language) override this executor.
 */
final class TransformExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $expression = $ctx->option('expression');
        if ($expression === null || $expression === '') {
            return $ctx->input('in', $ctx->inputs);
        }

        return Expr::evaluate($expression, $ctx->inputs);
    }
}
