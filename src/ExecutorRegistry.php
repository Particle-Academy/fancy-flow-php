<?php

declare(strict_types=1);

namespace FancyFlow;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Contracts\Resolver;
use FancyFlow\Exceptions\FlowException;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Support\NativeResolver;

/**
 * Maps nodes to the code that runs them. The PHP twin of fancy-flow's
 * `ExecutorRegistry` (a plain object keyed by node id / kind / `*`), with the
 * same three-tier lookup order:
 *
 *     node id  →  node kind  →  "*" fallback
 *
 * An executor may be a {@see NodeExecutor} instance, a `callable`/Closure, or a
 * class-string of either (resolved through a {@see Resolver} — `new` by default,
 * the container under Laravel). `bind()` / `bindNode()` are chainable.
 */
final class ExecutorRegistry
{
    /** @var array<string, callable|NodeExecutor|class-string> */
    private array $byKind = [];

    /** @var array<string, callable|NodeExecutor|class-string> */
    private array $byNode = [];

    private Resolver $resolver;

    public function __construct(?Resolver $resolver = null)
    {
        $this->resolver = $resolver ?? new NativeResolver();
    }

    /** Bind an executor to a node kind (e.g. `api_request`) or the `*` fallback. */
    public function bind(string $kind, callable|NodeExecutor|string $executor): static
    {
        $this->byKind[$kind] = $executor;

        return $this;
    }

    /** Bind an executor to a single node id — highest precedence. */
    public function bindNode(string $nodeId, callable|NodeExecutor|string $executor): static
    {
        $this->byNode[$nodeId] = $executor;

        return $this;
    }

    /**
     * Bind many kinds at once.
     *
     * @param array<string, callable|NodeExecutor|class-string> $map
     */
    public function bindMany(array $map): static
    {
        foreach ($map as $kind => $executor) {
            $this->bind($kind, $executor);
        }

        return $this;
    }

    public function hasKind(string $kind): bool
    {
        return isset($this->byKind[$kind]);
    }

    public function hasFallback(): bool
    {
        return isset($this->byKind['*']);
    }

    /**
     * Resolve the executor for a node, following id → kind → `*`. Returns a
     * callable `fn(ExecutionContext): mixed`, or null when nothing is bound.
     */
    public function resolveFor(FlowNode $node): ?callable
    {
        $raw = $this->byNode[$node->id]
            ?? ($node->type !== null ? ($this->byKind[$node->type] ?? null) : null)
            ?? $this->byKind['*']
            ?? null;

        return $raw === null ? null : $this->toCallable($raw);
    }

    private function toCallable(callable|NodeExecutor|string $executor): callable
    {
        if ($executor instanceof NodeExecutor) {
            return static fn (ExecutionContext $ctx): mixed => $executor->execute($ctx);
        }

        // A class-string is resolved to an instance first (DI-friendly).
        if (is_string($executor) && class_exists($executor)) {
            $instance = $this->resolver->make($executor);

            if ($instance instanceof NodeExecutor) {
                return static fn (ExecutionContext $ctx): mixed => $instance->execute($ctx);
            }

            if (is_callable($instance)) {
                return static fn (ExecutionContext $ctx): mixed => $instance($ctx);
            }

            throw new FlowException("Executor \"{$executor}\" must implement NodeExecutor or be invokable.");
        }

        // Function name, [$obj, 'method'], or Closure.
        if (is_callable($executor)) {
            return Closure::fromCallable($executor);
        }

        throw new FlowException('Executor must be a callable, a NodeExecutor, or a resolvable class-string.');
    }
}
