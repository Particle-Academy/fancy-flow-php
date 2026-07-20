<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Capabilities\LlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\LlmRouteRequest;
use Illuminate\Contracts\Container\Container;

/**
 * Lazy container-backed {@see LlmClient}.
 *
 * The core's capability slots hold INSTANCES, but resolving one at boot is
 * wrong twice over: it builds a service the app may never use, and it can drag
 * other singletons into existence before the app has finished configuring them
 * (resolving a capability that depends on the NodeKindRegistry froze that
 * registry before `fancy-flow.kinds` was read — a real bug, caught by
 * ServiceProviderTest). So the provider registers this proxy and the real
 * client is pulled from the container on first USE.
 */
final class ContainerLlmClient implements LlmClient
{
    public function __construct(private readonly Container $container) {}

    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice
    {
        return $this->container->make(LlmClient::class)->chooseRoute($request);
    }
}
