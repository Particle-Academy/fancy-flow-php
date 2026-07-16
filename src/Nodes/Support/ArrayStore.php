<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/** An in-memory {@see KeyValueStore}. Deterministic; ideal for tests and local runs. */
final class ArrayStore implements KeyValueStore
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
