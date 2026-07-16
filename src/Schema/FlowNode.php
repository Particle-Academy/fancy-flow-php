<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A runtime node in a {@see FlowGraph}. The PHP twin of fancy-flow's
 * `FlowNode` (an xyflow node with a `data` slot), flattened for ergonomics.
 *
 * `$type` is the registry kind (e.g. "memory_store") — the same value the
 * TS side stores as both the xyflow node `type` and `data.kind`.
 *
 * `$inputs` / `$outputs` are intentionally nullable: `null` means "ports not
 * declared" (the engine falls back to a single `out` port), whereas an empty
 * array means "explicitly no ports" (a terminal node). {@see \FancyFlow\Engine\FlowRunner}
 * relies on that distinction to match the TS engine byte-for-byte.
 */
final class FlowNode
{
    /**
     * @param array<string,mixed>       $config
     * @param list<PortDescriptor>|null $inputs
     * @param list<PortDescriptor>|null $outputs
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $type = null,
        public readonly float $x = 0.0,
        public readonly float $y = 0.0,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly array $config = [],
        public readonly ?array $inputs = null,
        public readonly ?array $outputs = null,
    ) {}

    /** The registry kind name — alias for {@see $type}. */
    public function kind(): ?string
    {
        return $this->type;
    }

    /** Read a single config value with a dot-free key. */
    public function configValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
