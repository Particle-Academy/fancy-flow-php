<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * Options for a single {@see \FancyFlow\Engine\FlowRunner::run()} call. Mirrors
 * fancy-flow's `RunOptions`.
 */
final class RunOptions
{
    /**
     * @param int|null                              $timeoutMs     Stop the run after this many ms. Null = no timeout.
     * @param AbortSignal|null                      $signal        Cooperative cancellation. Checked before each node.
     * @param array<string,array<string,mixed>>     $initialInputs Inputs seeded to entry nodes, keyed by node id then port.
     */
    public function __construct(
        public readonly ?int $timeoutMs = null,
        public readonly ?AbortSignal $signal = null,
        public readonly array $initialInputs = [],
    ) {}
}
