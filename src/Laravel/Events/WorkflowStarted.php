<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/** Dispatched when a workflow run begins. Mirrors the `run-start` RunEvent. */
final class WorkflowStarted
{
    public function __construct(
        public readonly string $runId,
        public readonly ?string $workflowId = null,
    ) {}
}
