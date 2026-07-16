<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Data;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\KeyValueStore;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `data_store` — get / set / delete / query / list against a host store. Keys
 * are namespaced by `table` (as `table/key`). `query`/`list` scan the table and
 * (for query) filter rows by the `where` map. The framework-free default backs
 * onto an in-memory {@see KeyValueStore}; hosts bind Eloquent / cache.
 */
final class DataStoreExecutor implements NodeExecutor
{
    public function __construct(private readonly KeyValueStore $store) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $operation = (string) $ctx->option('operation', 'get');
        $table = (string) $ctx->option('table', 'default');
        $key = $ctx->option('key');

        return match ($operation) {
            'set' => $this->set($ctx, $table, (string) $key),
            'delete' => $this->delete($table, (string) $key),
            'list' => $this->rows($table),
            'query' => $this->query($ctx, $table),
            default => $this->store->get($this->namespaced($table, (string) $key)),
        };
    }

    private function set(ExecutionContext $ctx, string $table, string $key): mixed
    {
        $value = Expr::evaluate($ctx->option('value'), $ctx->inputs);
        $this->store->set($this->namespaced($table, $key), $value);

        return $value;
    }

    /** @return array{deleted:string} */
    private function delete(string $table, string $key): array
    {
        $this->store->delete($this->namespaced($table, $key));

        return ['deleted' => $key];
    }

    /** @return array<string,mixed> */
    private function rows(string $table): array
    {
        $prefix = $table.'/';
        $rows = [];
        foreach ($this->store->all() as $storeKey => $value) {
            if (str_starts_with($storeKey, $prefix)) {
                $rows[substr($storeKey, strlen($prefix))] = $value;
            }
        }

        return $rows;
    }

    /** @return list<mixed> */
    private function query(ExecutionContext $ctx, string $table): array
    {
        $where = $ctx->option('where', []);
        $where = is_array($where) ? $where : [];

        $matches = [];
        foreach ($this->rows($table) as $row) {
            if ($this->matchesWhere($row, $where)) {
                $matches[] = $row;
            }
        }

        return $matches;
    }

    /** @param array<string,mixed> $where */
    private function matchesWhere(mixed $row, array $where): bool
    {
        if ($where === []) {
            return true;
        }
        if (! is_array($row)) {
            return false;
        }
        foreach ($where as $field => $expected) {
            if (($row[$field] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function namespaced(string $table, string $key): string
    {
        return $table.'/'.$key;
    }
}
