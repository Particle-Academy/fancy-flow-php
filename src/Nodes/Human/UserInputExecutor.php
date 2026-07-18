<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Human;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `user_input` — in a live host this pauses the flow until the user submits the
 * configured form. The framework-free default treats the seeded/incoming values
 * as the submission and emits them on `out` (label: values).
 *
 * The Laravel layer turns this into a real durable pause + resume: inside a
 * queued run {@see \FancyFlow\Laravel\Nodes\DurableUserInputExecutor} takes over,
 * parking the run as `awaiting_input` until
 * {@see \FancyFlow\Laravel\Models\WorkflowRun::submitInput()} records the values.
 */
final class UserInputExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return $ctx->inputs['values'] ?? $ctx->input('in', $ctx->inputs);
    }
}
