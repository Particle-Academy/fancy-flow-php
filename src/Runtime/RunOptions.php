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
     * @param RunIdentity|array<string,mixed>|string|null $run      Who is running, so a writing node can derive a stable
     *                                                             idempotency key. A bare string is taken as the run key.
     *                                                             DELIBERATELY NOT DEFAULTED: a key minted per call would
     *                                                             change on every whole-run retry, which is exactly the
     *                                                             failure an idempotency key exists to prevent — so a host
     *                                                             that has not supplied one gets `$ctx->run === null` and a
     *                                                             connector that declines to write blind, rather than a
     *                                                             plausible-looking key that double-charges.
     */
    public readonly ?RunIdentity $run;

    public function __construct(
        public readonly ?int $timeoutMs = null,
        public readonly ?AbortSignal $signal = null,
        public readonly array $initialInputs = [],
        public readonly array $resumeOutputs = [],
        public readonly int $depth = 0,
        RunIdentity|array|string|null $run = null,
    ) {
        $this->run = $run === null ? null : RunIdentity::from($run);
    }
}
