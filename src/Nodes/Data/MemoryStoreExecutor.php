<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Data;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\KeyValueStore;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `memory_store` — read / write / append per-conversation memory against a
 * {@see KeyValueStore}. The value (for write/append) is resolved through
 * {@see Expr} against the node's inputs.
 */
final class MemoryStoreExecutor implements NodeExecutor
{
    public function __construct(private readonly KeyValueStore $store) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $operation = (string) $ctx->option('operation', 'read');
        $key = (string) $ctx->option('key', '');

        return match ($operation) {
            'write' => $this->write($ctx, $key),
            'append' => $this->append($ctx, $key),
            default => $this->store->get($key),
        };
    }

    private function write(ExecutionContext $ctx, string $key): mixed
    {
        $value = Expr::evaluate($ctx->option('value'), $ctx->inputs);
        $this->store->set($key, $value);

        return $value;
    }

    private function append(ExecutionContext $ctx, string $key): mixed
    {
        $value = Expr::evaluate($ctx->option('value'), $ctx->inputs);
        $current = $this->store->get($key, []);
        $list = is_array($current) ? array_values($current) : [$current];
        $list[] = $value;
        $this->store->set($key, $list);

        return $list;
    }
}
