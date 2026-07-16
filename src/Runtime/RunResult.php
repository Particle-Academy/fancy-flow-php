<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * The result of a run. Mirrors fancy-flow's `RunResult`, with an extra
 * `events` array (opt-in) so callers that did not pass an `onEvent` sink can
 * still inspect the full stream after the fact.
 */
final class RunResult
{
    /**
     * @param array<string,mixed> $outputs Executor results keyed by node id.
     * @param list<RunEvent>       $events  The full event stream, in order.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly array $outputs = [],
        public readonly ?string $error = null,
        public readonly array $events = [],
    ) {}

    public function output(string $nodeId, mixed $default = null): mixed
    {
        return $this->outputs[$nodeId] ?? $default;
    }
}
