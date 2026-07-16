<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/** Dispatched on each node status transition. Mirrors the `node-status` RunEvent. */
final class NodeStatusChanged
{
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $status,
        public readonly ?string $text = null,
    ) {}
}
