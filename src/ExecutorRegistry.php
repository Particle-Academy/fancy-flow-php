<?php

declare(strict_types=1);

namespace FancyFlow;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Contracts\Resolver;
use FancyFlow\Exceptions\FlowException;
use FancyFlow\Registry\Builtin;
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

    /**
     * The catalogue this registry consults — the host's when it supplied one.
     *
     * Exposed because the ENGINE needs it. `FlowRunner::activatedPorts` resolves
     * a node's output ports through its kind, and it was reaching for
     * `NodeKindRegistry::default()` — the static builtin catalogue — so a kind
     * the host registered was invisible at exactly the moment its ports
     * mattered, and the node fell through to publishing a single `out`.
     *
     * The consequence is not "some ports are missing". `collectInputs` binds a
     * payload only when `"<sourceId>:<handle>"` exists, so an edge leaving a
     * host kind's real port found nothing and delivered NOTHING — no failure,
     * no warning, and a downstream template that is completely correct
     * rendering empty because the payload never arrived to have a field in it.
     *
     * Reported by a consumer who misdiagnosed two filed issues off the back of
     * it. It also broke the same-JSON-same-outputs guarantee outright, since
     * the TS side resolves ports through the registry a host registers into.
     */
    public function kinds(): NodeKindRegistry
    {
        return $this->kinds ?? NodeKindRegistry::default();
    }

    /**
     * Bind an executor to a node kind (e.g. `api_request`) or the `*` fallback.
     *
     * **Alias-aware for kinds this registry knows.** Binding `user_input` binds
     * `@particle-academy/user_input` and `@fancy/user_input` with it, because
     * they are the same kind and a caller overriding one means the kind.
     *
     * Keying literally was a silent trap, and it cost a human gate (#4). The
     * builtins are bound under all three ids, `resolveFor` tries the node's
     * literal id FIRST, and the durable `user_input` override was bound under
     * the bare name only — so a node saved as `@particle-academy/user_input`
     * matched the plain pass-through executor and the run went straight past
     * the person it was meant to stop for. Nothing errored; the run just
     * completed. Every host overriding a builtin by bare name had the same
     * trap waiting.
     *
     * An UNKNOWN kind is still bound literally. Expanding one would claim
     * `@particle-academy/<name>` for somebody else's node, which is the
     * opposite mistake.
     */
    public function bind(string $kind, callable|NodeExecutor|string $executor): static
    {
        $this->byKind[$kind] = $executor;

        // The `*` fallback is a sentinel, not a kind: it has no aliases and
        // must never be expanded into namespaced spellings.
        if ($kind === '*') {
            return $this;
        }

        foreach ($this->aliasIdsFor($kind) as $id) {
            $this->byKind[$id] = $executor;
        }

        return $this;
    }

    /**
     * Every id a KNOWN kind answers to, minus the one just bound.
     *
     * Declared aliases come from the kind registry, because convention alone
     * cannot get you from `llm_branch` to `llm_router` — only the kind's own
     * alias list does. Convention variants are added for a kind that is
     * registered but whose ids omit a spelling.
     *
     * Empty for a kind the registry has never heard of.
     *
     * @return list<string>
     */
    private function aliasIdsFor(string $kind): array
    {
        $registry = $this->kinds ?? NodeKindRegistry::default();
        $declared = $registry->idsFor($kind);

        if ($declared === []) {
            // The kind registry is not necessarily populated when a binding is
            // made — a forked registry overriding a builtin often has none at
            // all — so fall back to the builtin index, which is the SAME
            // authority the base bindings were expanded from. Agreeing with it
            // by construction is the whole point.
            $declared = Builtin::kindIdIndex()[KindId::bare($kind)] ?? [];
        }

        if ($declared === []) {
            return [];
        }

        return array_values(array_filter(
            array_unique([...$declared, ...KindId::variants($kind)]),
            // `*` is excluded in BOTH directions, and only one of them was
            // covered. `bind()` already refuses to expand the sentinel OUT to
            // namespaced spellings — but nothing stopped an alias expanding IN
            // to it, so a kind that answers to `*` turned `bind('everything')`
            // into a GLOBAL FALLBACK for every unmatched node in the graph.
            // Silently: a fallback that exists and a fallback that does not both
            // let the run complete.
            //
            // The `*` slot may only ever be written by an explicit `bind('*')`.
            // Found by `flow/executor-resolution/0107`, which TypeScript already
            // satisfied because it expands aliases at LOOKUP time and never
            // looks the sentinel up as a kind.
            static fn (string $id): bool => $id !== $kind && $id !== '*',
        ));
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
