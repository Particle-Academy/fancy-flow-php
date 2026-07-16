<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A directed connection between two nodes' ports. Mirrors fancy-flow's
 * `FlowEdge` (xyflow's `Edge`).
 *
 * `sourceHandle` / `targetHandle` name the ports; when omitted the engine
 * uses `out` on the source and `in` on the target.
 */
final class FlowEdge
{
    public function __construct(
        public readonly string $id,
        public readonly string $source,
        public readonly string $target,
        public readonly ?string $sourceHandle = null,
        public readonly ?string $targetHandle = null,
        public readonly ?string $label = null,
    ) {}
}
