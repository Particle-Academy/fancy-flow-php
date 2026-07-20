<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

use Closure;
use FancyFlow\Exceptions\RunAborted;
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
     */
    public function __construct(
        public readonly FlowNode $node,
        public readonly array $inputs,
        private readonly Closure $emit,
        public readonly int $depth = 0,
    ) {}

    /** Stop the run. Throws {@see RunAborted}; the runner records the reason. */
    public function abort(?string $reason = null): never
    {
        throw new RunAborted($reason ?? 'aborted');
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
