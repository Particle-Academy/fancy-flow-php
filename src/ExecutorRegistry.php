<?php

declare(strict_types=1);

namespace FancyFlow;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Contracts\Resolver;
use FancyFlow\Exceptions\FlowException;
use FancyFlow\Registry\KindId;
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

    /** The catalogue consulted for kind aliases; the shared registry by default. */
    private ?NodeKindRegistry $kinds;

    public function __construct(?Resolver $resolver = null, ?NodeKindRegistry $kinds = null)
    {
        $this->resolver = $resolver ?? new NativeResolver();
        $this->kinds = $kinds;
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

    /**
     * A shallow copy sharing the resolver. Bind on the fork to override kinds
     * for a single run without mutating the shared registry (e.g. the durable
     * job swapping in a pausing approval executor).
     */
    public function fork(): self
    {
        $copy = new self($this->resolver, $this->kinds);
        $copy->byKind = $this->byKind;
        $copy->byNode = $this->byNode;

        return $copy;
    }

    /** Alias-aware: true when a binding exists under ANY id this kind answers to. */
    public function hasKind(string $kind): bool
    {
        foreach ($this->kindCandidates($kind) as $candidate) {
            if (isset($this->byKind[$candidate])) {
                return true;
            }
        }

        return false;
    }

    public function hasFallback(): bool
    {
        return isset($this->byKind['*']);
    }

    /**
     * Resolve the executor for a node, following id → kind → `*`. Returns a
     * callable `fn(ExecutionContext): mixed`, or null when nothing is bound.
     *
     * The kind step tries EVERY id the kind answers to, not just the one
     * written in the graph. Canonical ids are namespaced
     * (`@particle-academy/branch`) while a host may well have bound its
     * executor under the bare name — resolving only the literal string would
     * turn a rename into a breaking change in disguise.
     */
    public function resolveFor(FlowNode $node): ?callable
    {
        $raw = $this->byNode[$node->id] ?? null;

        if ($raw === null && $node->type !== null) {
            foreach ($this->kindCandidates($node->type) as $candidate) {
                if (isset($this->byKind[$candidate])) {
                    $raw = $this->byKind[$candidate];
                    break;
                }
            }
        }

        $raw ??= $this->byKind['*'] ?? null;

        return $raw === null ? null : $this->toCallable($raw);
    }

    /**
     * Every id a binding for `$kind` might have been registered under, in
     * preference order.
     *
     * Explicit aliases from the kind registry come first — a custom kind may
     * declare any alias it likes — then the naming-convention variants, which
     * cover bindings made against a kind that was never registered here.
     *
     * @return list<string>
     */
    private function kindCandidates(string $kind): array
    {
        $registry = $this->kinds ?? NodeKindRegistry::default();

        return array_values(array_unique([
            $kind,
            ...$registry->idsFor($kind),
            ...KindId::variants($kind),
        ]));
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
