<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A connection point on a node. Mirrors fancy-flow's `PortDescriptor`.
 *
 * Ports are the handles edges attach to. `id` is what edges reference via
 * `sourceHandle` / `targetHandle`; the default input port is `in` and the
 * default output port is `out`.
 */
final class PortDescriptor
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $label = null,
        /** Optional logical type for hosts that want to validate connections. */
        public readonly ?string $type = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? 'out'),
            label: isset($raw['label']) ? (string) $raw['label'] : null,
            type: isset($raw['type']) ? (string) $raw['type'] : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
        ], static fn ($v) => $v !== null);
    }
}
