<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Contracts\Resolver;
use Illuminate\Contracts\Container\Container;

/**
 * The Laravel {@see Resolver} — resolves executor class-strings through the
 * service container, so executors get full constructor dependency injection
 * (HTTP client, Eloquent, your services). Swapped in for the framework-free
 * `NativeResolver` by the service provider.
 */
final class ContainerResolver implements Resolver
{
    public function __construct(private readonly Container $container) {}

    public function make(string $class): object
    {
        return $this->container->make($class);
    }
}
