<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * A node produced usable output, but some of the work represented by it failed.
 *
 * The runner publishes the value so downstream summary/reporting nodes can run,
 * then settles the enclosing run as {@see RunResult::PARTIAL}. This is not an
 * exception: durable drivers must not retry successful item work merely because
 * a sibling item failed.
 */
final class PartialResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $error,
    ) {}
}
