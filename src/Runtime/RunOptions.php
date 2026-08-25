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
     * @param array<string,mixed>                   $props         Values for the inputs the GRAPH declares, passed by NAME.
     *                                                             `initialInputs` is keyed by node id, so a caller had to know the
     *                                                             trigger was called `t` and a rename broke every caller while the
     *                                                             graph stayed valid. These are checked against the graph's own
     *                                                             declaration, so a misspelling fails the run instead of sitting unread.
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
        public readonly array $props = [],
        public readonly array $resumeOutputs = [],
        public readonly int $depth = 0,
        RunIdentity|array|string|null $run = null,
        /**
         * Which ENTRY POINTS are live — the ids of nodes with NO incoming edges
         * that this run should start from. `null` means unset and behaves
         * exactly as before this option existed.
         *
         * A graph may hold more than one trigger — a `manual_trigger` for
         * hand-testing beside the event trigger that runs it for real — and a
         * trigger has no inbound edges, which IS the readiness rule. So without
         * this, every trigger's branch runs on every run, whichever one fired.
         * The triggers themselves are harmless; everything downstream of the
         * ones that did not fire is not. A `user_input` stranded on the manual
         * branch parks an event-driven run to ask a person for data the event
         * already supplied, which from outside looks like the event trigger
         * being ignored.
         *
         * Naming the live entry points makes the others INACTIVE, and the
         * existing "at least one active inbound edge" rule then skips
         * everything reachable only from them. No new routing logic.
         *
         * Three edges worth knowing, each pinned by `flow/entry-points` in
         * `particle-academy/fancy-conformance`:
         *  - `null` (unset) is NOT `[]`. Unset runs every entry point; an empty
         *    list says none is live and runs nothing.
         *  - A node reachable from SEVERAL entry points still runs when any one
         *    of them fires — one active inbound edge is enough, as always.
         *  - Naming a node that HAS inbound edges names no entry point, so every
         *    real entry is inactive and nothing runs. That falls out of the rule
         *    rather than being special-cased; validate your ids if you want a
         *    typo to be loud, because the runtime cannot tell one from a
         *    deliberate empty selection.
         *
         * @var list<string>|null
         */
        public readonly ?array $entryNodes = null,
    ) {
        $this->run = $run === null ? null : RunIdentity::from($run);
    }
}
