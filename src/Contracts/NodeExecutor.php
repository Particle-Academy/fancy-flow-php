<?php

declare(strict_types=1);

namespace FancyFlow\Contracts;

use FancyFlow\Runtime\ExecutionContext;

/**
 * The behavior half of a node kind. A registered executor can be:
 *
 *   - a class implementing this interface (resolved via a {@see Resolver},
 *     so Laravel can inject dependencies through the container),
 *   - a plain `callable` / Closure `fn(ExecutionContext $ctx): mixed`, or
 *   - a class-string of either of the above.
 *
 * The returned value becomes the node's output and drives port activation
 * (see {@see \FancyFlow\Runtime\Port}).
 */
interface NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed;
}
