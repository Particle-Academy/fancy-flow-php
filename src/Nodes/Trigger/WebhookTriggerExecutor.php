<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Trigger;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `webhook_trigger` — entry point for an inbound HTTP request. Emits the request
 * payload (seeded under `payload`, or the whole seed) on the `out` port.
 */
final class WebhookTriggerExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return $ctx->inputs['payload'] ?? $ctx->inputs;
    }
}
