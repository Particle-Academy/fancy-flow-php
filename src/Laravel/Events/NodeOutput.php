<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/** Dispatched when a node publishes a value on a port. Mirrors the `node-output` RunEvent. */
final class NodeOutput
{
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $portId,
        public readonly mixed $value,
    ) {}
}
