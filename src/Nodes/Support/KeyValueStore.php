<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * A minimal key-value store the memory / data executors read and write. The
 * framework-free default is {@see ArrayStore}; the Laravel layer binds cache or
 * Eloquent-backed implementations.
 */
interface KeyValueStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function delete(string $key): void;

    public function has(string $key): bool;

    /** @return array<string,mixed> */
    public function all(): array;
}
