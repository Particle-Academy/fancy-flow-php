<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

use Closure;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Schema\FlowNode;

/**
 * Everything an executor gets when it runs — the PHP twin of the TS executor
 * `ctx { node, inputs, abort, emit }`.
 *
 *   - `$node`   the node being executed (id, kind, config, ports).
 *   - `$inputs` values arriving on each input port, keyed by port id
 *               (default port is `in`), merged with any seeded initial inputs.
 *   - `abort()` stops the whole run (throws {@see RunAborted}).
 *   - `emit()`  streams a {@see RunEvent} to the run's event sink.
 */
final class ExecutionContext
{
    /**
     * @param array<string,mixed>     $inputs
     * @param Closure(RunEvent):void  $emit
     * @param int                     $depth how deep this run is nested; `subflow`
     *        reads it to enforce a depth limit and passes depth + 1 to its child.
     * @param RunIdentity|null        $run   who is running, and which attempt of which step
     *        this is. `$ctx->run->stepKey($ctx->node->id)` is the idempotency key for a node
     *        that writes to somebody else's system — stable across retries of this step,
     *        distinct for every other execution of the same node. NULL when the host supplied
     *        no identity, and that is a real answer: a write with no key must decline or
     *        accept one attempt, never invent a key.
     */
    public function __construct(
        public readonly FlowNode $node,
        public readonly array $inputs,
        private readonly Closure $emit,
        public readonly int $depth = 0,
        public readonly ?RunIdentity $run = null,
        /**
         * The registry THIS run is executing against.
         *
         * Handed down so an executor that starts a NESTED run gives the child
         * the same executors as the parent. `SubflowExecutor` previously fell
         * back to `Builtin::executors()` — the BARE builtins — because the
         * composed registry does not exist yet when `Builtin::executors()`
         * constructs it, and nothing could be passed at that point. The child
         * therefore lost every host executor, the `agent` binding and the
         * container resolver: a host kind resolved at top level and vanished
         * one level down, and a host that had REPLACED a builtin got the
         * package's version inside the child (issue #7).
         *
         * Inheriting through the context rather than through construction is
         * what makes it unforgettable — any future nesting executor gets it
         * without opting in.
         */
        public readonly ?ExecutorRegistry $executors = null,
    ) {}

    /** Stop the run. Throws {@see RunAborted}; the runner records the reason. */
    public function abort(?string $reason = null): never
    {
        throw new RunAborted($reason ?? 'aborted');
    }

    /**
     * Halt the run to wait for a person.
     *
     * Node authors should reach for this rather than hand-encoding a reason, so
     * the format stays ours to change:
     *
     *     $values = $ctx->inputs['values'] ?? null;
     *     if ($values === null) {
     *         $ctx->pauseForHuman('input', ['fields' => $fields]);
     *     }
     *
     * Note the strict null check — an empty submission (`[]`) is a real answer
     * and must resume. A truthiness test pauses forever on an empty form.
     */
    public function pauseForHuman(string $awaiting, mixed $detail = null): never
    {
        $this->abort(Pause::encode(new PauseSignal($this->node->id, $awaiting, $detail)));
    }

    /** Stream a status update or partial output to the run feed. */
    public function emit(RunEvent $event): void
    {
        ($this->emit)($event);
    }

    /** Read one input port's value (default port `in`). */
    public function input(string $port = 'in', mixed $default = null): mixed
    {
        return $this->inputs[$port] ?? $default;
    }

    /** The node's resolved config array. */
    public function config(): array
    {
        return $this->node->config;
    }

    /** Read one config key. */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->node->config[$key] ?? $default;
    }
}
