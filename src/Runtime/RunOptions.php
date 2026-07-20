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
     * @param array<string,mixed>                   $resumeOutputs Outputs of nodes already completed in a prior run,
     *                                                             keyed by node id. Such a node is NOT re-executed — its
     *                                                             stored output is republished on its ports, reproducing
     *                                                             the same routing. The primitive durable resume builds on.
     * @param int                                   $depth         Nesting depth — `subflow` passes depth + 1 to the child
     *                                                             graph it runs, so runaway recursion can be reported BY
     *                                                             NAME instead of overflowing the stack.
     */
    public function __construct(
        public readonly ?int $timeoutMs = null,
        public readonly ?AbortSignal $signal = null,
        public readonly array $initialInputs = [],
        public readonly array $resumeOutputs = [],
        public readonly int $depth = 0,
    ) {}
}
