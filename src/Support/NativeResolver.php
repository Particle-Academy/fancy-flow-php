<?php

declare(strict_types=1);

namespace FancyFlow\Support;

use FancyFlow\Contracts\Resolver;
use FancyFlow\Exceptions\FlowException;

/**
 * The default {@see Resolver} — instantiates a class with `new`. Works for any
 * executor whose constructor needs no arguments. Hosts that need dependency
 * injection supply their own resolver (the Laravel layer binds the container).
 */
final class NativeResolver implements Resolver
{
    public function make(string $class): object
    {
        if (! class_exists($class)) {
            throw new FlowException("Executor class \"{$class}\" does not exist.");
        }

        return new $class();
    }
}
