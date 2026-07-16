<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Human;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `user_input` — in a live host this pauses the flow until the user submits the
 * configured form. The framework-free default treats the seeded/incoming values
 * as the submission and emits them on `out` (label: values). The 0.3 Laravel
 * layer turns this into a real durable pause + resume.
 */
final class UserInputExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return $ctx->inputs['values'] ?? $ctx->input('in', $ctx->inputs);
    }
}
