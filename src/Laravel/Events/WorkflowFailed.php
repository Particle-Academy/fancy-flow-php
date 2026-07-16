<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/** Dispatched when a run ends in error. Mirrors `run-error` / a failed `run-end`. */
final class WorkflowFailed
{
    public function __construct(
        public readonly string $runId,
        public readonly string $error,
    ) {}
}
