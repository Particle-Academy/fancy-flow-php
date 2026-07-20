<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Schema\FlowGraph;
use Illuminate\Contracts\Container\Container;

/**
 * Lazy container-backed {@see WorkflowResolver} — the `subflow` counterpart of
 * {@see ContainerLlmClient}, and the one that actually forced the pattern:
 * {@see EloquentWorkflowResolver} depends on the NodeKindRegistry, so resolving
 * it at boot froze that registry before config-declared kinds were added.
 */
final class ContainerWorkflowResolver implements WorkflowResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
    {
        return $this->container->make(WorkflowResolver::class)->resolve($ref, $version);
    }
}
