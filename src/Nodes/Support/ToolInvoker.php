<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * Runs a host-registered tool by name for the tool_use executor. The default
 * {@see EchoToolInvoker} echoes the call; the Laravel layer routes to registered
 * actions or other flow nodes.
 */
interface ToolInvoker
{
    /** @param array<string,mixed> $args */
    public function invoke(string $tool, array $args = []): mixed;
}
