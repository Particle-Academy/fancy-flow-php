<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\VectorStore;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `embed_search` — embed the `query` and search a {@see VectorStore}, returning
 * the top-K matches. The query is resolved through {@see Expr} against the
 * node's inputs.
 */
final class EmbedSearchExecutor implements NodeExecutor
{
    public function __construct(private readonly VectorStore $vectors) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $query = Expr::text(Expr::evaluate($ctx->option('query', ''), $ctx->inputs));
        $topK = (int) $ctx->option('topK', 5);

        return [
            'query' => $query,
            'matches' => $this->vectors->search($query, $topK),
        ];
    }
}
