<?php

declare(strict_types=1);

namespace FancyFlow\Contracts;

/**
 * Turns a class-string into an instance. The framework-free default
 * ({@see \FancyFlow\Support\NativeResolver}) just calls `new $class()`; the
 * 0.2 Laravel layer swaps in a container-backed resolver so executors get
 * full constructor dependency injection.
 */
interface Resolver
{
    public function make(string $class): object;
}
