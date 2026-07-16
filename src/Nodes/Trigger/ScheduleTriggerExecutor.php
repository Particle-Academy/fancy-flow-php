<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Trigger;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `schedule_trigger` — entry point fired on a cron schedule (the host scheduler
 * decides *when*; this just runs the branch). Emits the schedule context merged
 * with any seeded payload.
 */
final class ScheduleTriggerExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return array_merge(
            [
                'cron' => $ctx->option('cron'),
                'timezone' => $ctx->option('timezone', 'UTC'),
            ],
            is_array($ctx->inputs) ? $ctx->inputs : [],
        );
    }
}
