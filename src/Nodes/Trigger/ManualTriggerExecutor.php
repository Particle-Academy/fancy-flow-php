<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Trigger;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `manual_trigger` — the entry point fired when a user clicks Run. Passes the
 * seeded payload (from `RunOptions::initialInputs`) straight through on `out`.
 */
final class ManualTriggerExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return $ctx->inputs;
    }
}
