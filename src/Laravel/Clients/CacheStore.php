<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Clients;

use FancyFlow\Nodes\Support\KeyValueStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * A cache-backed {@see KeyValueStore} for the memory_store / data_store default
 * executors under Laravel. Values persist across requests via the configured
 * cache store. A small companion index entry tracks the keys so `all()` (used by
 * data_store's list/query) can enumerate — the one thing a bare cache can't do.
 *
 * The index is read-modify-write, so heavy concurrent writers can race; the 0.3
 * Eloquent-backed data store is the durable, transactional option.
 */
final class CacheStore implements KeyValueStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'fancy_flow:',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->prefix.$key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->cache->forever($this->prefix.$key, $value);
        $index = $this->index();
        $index[$key] = true;
        $this->cache->forever($this->indexKey(), $index);
    }

    public function delete(string $key): void
    {
        $this->cache->forget($this->prefix.$key);
        $index = $this->index();
        unset($index[$key]);
        $this->cache->forever($this->indexKey(), $index);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->prefix.$key);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys($this->index()) as $key) {
            $out[$key] = $this->cache->get($this->prefix.$key);
        }

        return $out;
    }

    private function indexKey(): string
    {
        return $this->prefix.'__index__';
    }

    /** @return array<string,bool> */
    private function index(): array
    {
        $index = $this->cache->get($this->indexKey(), []);

        return is_array($index) ? $index : [];
    }
}
