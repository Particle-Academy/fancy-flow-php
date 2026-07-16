<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `wait` — a pause point. The framework-free default does NOT actually sleep
 * (deterministic + fast in tests); it records the requested wait and passes the
 * input through. A durable host executor (0.3) sleeps, waits for a timestamp, or
 * suspends the run until an external event.
 */
final class WaitExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $mode = (string) $ctx->option('mode', 'duration');
        $duration = $ctx->option('duration');
        $ctx->emit(RunEvent::log('info', "wait ({$mode}) — not sleeping in framework-free mode", $ctx->node->id));

        return [
            'waited' => $mode,
            'duration' => $duration,
            'input' => $ctx->input('in', $ctx->inputs),
        ];
    }
}
