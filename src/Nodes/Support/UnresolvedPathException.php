<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

use RuntimeException;

/** Thrown by {@see UnresolvedPolicy::Throw} when an expression path does not resolve. */
final class UnresolvedPathException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct(sprintf(
            'Expression path "%s" did not resolve. Under the "throw" policy an unresolvable path '
            .'is an error rather than an empty string.',
            $path,
        ));
    }
}
