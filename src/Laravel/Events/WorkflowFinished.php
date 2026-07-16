<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/** Dispatched when a run ends successfully. Mirrors the `run-end` RunEvent (ok=true). */
final class WorkflowFinished
{
    /** @param array<string,mixed> $outputs */
    public function __construct(
        public readonly string $runId,
        public readonly bool $ok,
        public readonly array $outputs = [],
    ) {}
}
