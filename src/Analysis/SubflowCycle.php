<?php

declare(strict_types=1);

namespace FancyFlow\Analysis;

use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/**
 * Find a `subflow` loop BEFORE the graph is saved, rather than after a run has
 * already done the work N times.
 *
 * ## Why this is not the depth cap
 *
 * {@see \FancyFlow\Nodes\Structural\SubflowExecutor} caps recursion depth at
 * runtime, which is the correct backstop and stays. But by the time it fires,
 * every node ABOVE the subflow has run on each pass — writes, webhooks,
 * notifications, LLM calls — and the author is handed an opaque failure from
 * deep inside a run while the graph that caused it is still saved and still
 * runnable.
 *
 * ## Why the host cannot do this itself
 *
 * It can, and at least one did: roughly ninety lines walking `config.workflow`.
 * The problem is that such code hard-codes the `subflow` config key and the
 * ref/version resolution rules — both of which are THIS package's contract, not
 * the host's — so every host reinvents it, and none of them learn about a new
 * composition kind. That is why the walker below also descends into `subgraph`.
 *
 * ## Why it needs a resolver
 *
 * `Workflow::import()` validates one schema in isolation and cannot see
 * A → B → A, because each graph is individually valid. Only the resolver knows
 * what a ref points at, and only the host has one.
 *
 * The chain matters more than the boolean: "this loops" is much less useful than
 * naming the step that closes it.
 *
 * The PHP twin of fancy-flow's `findSubflowCycle`.
 */
final class SubflowCycle
{
    /**
     * @param  string|null  $rootRef  what to call the graph being checked, so it can
     *                                appear in the chain. Omit for an unsaved draft.
     * @return list<string> the refs forming the loop, closing on the repeated one
     *                      (`['Daily Planner', 'Digest', 'Daily Planner']`), or `[]`
     *                      when the graph is safe.
     */
    public static function find(
        FlowGraph $graph,
        WorkflowResolver $resolver,
        ?string $rootRef = null,
    ): array {
        $rootKey = $rootRef === null ? null : self::key($rootRef, null);

        return self::walk(
            $graph,
            $resolver,
            path: $rootRef === null ? [] : [$rootRef],
            onPath: $rootKey === null ? [] : [$rootKey => true],
        );
    }

    /**
     * @param  list<string>        $path   human-readable chain so far
     * @param  array<string,true>  $onPath keys currently being visited
     * @return list<string>
     */
    private static function walk(
        FlowGraph $graph,
        WorkflowResolver $resolver,
        array $path,
        array $onPath,
    ): array {
        foreach (self::references($graph->nodes) as [$ref, $version]) {
            $key = self::key($ref, $version);

            // Membership is tracked PER PATH, not globally: a diamond (two
            // branches that both call the same child) revisits a ref without
            // looping, and treating that as a cycle would refuse a good graph.
            if (isset($onPath[$key])) {
                return [...$path, $ref];
            }

            $child = $resolver->resolve($ref, $version);

            // A ref that does not resolve is a different problem, reported
            // elsewhere. Refusing the save for it would block authoring a parent
            // before its child exists.
            if ($child instanceof WorkflowResolutionFailure || ! $child instanceof FlowGraph) {
                continue;
            }

            $found = self::walk(
                $child,
                $resolver,
                [...$path, $ref],
                [...$onPath, $key => true],
            );

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * Every subflow reference reachable from these nodes without leaving the
     * graph — which includes those nested inside an inline `subgraph`, whose
     * graph lives in node config rather than behind a ref.
     *
     * Accepts both {@see FlowNode} objects and the raw arrays an inline subgraph
     * carries, because the nested graph has not been imported into objects.
     *
     * @param  iterable<FlowNode|array<string,mixed>>  $nodes
     * @return list<array{0:string,1:int|null}>
     */
    private static function references(iterable $nodes): array
    {
        $found = [];

        foreach ($nodes as $node) {
            $type = $node instanceof FlowNode ? $node->type : ($node['type'] ?? null);
            $config = $node instanceof FlowNode ? $node->config : ($node['config'] ?? []);

            if (! is_string($type) || ! is_array($config)) {
                continue;
            }

            switch (self::kindName($type)) {
                case 'subflow':
                    $ref = trim((string) ($config['workflow'] ?? ''));
                    if ($ref !== '') {
                        $found[] = [$ref, self::version($config['version'] ?? null)];
                    }
                    break;

                case 'subgraph':
                    // Cannot cycle by itself — it embeds a graph rather than
                    // naming one — but a `subflow` inside it can, and a walker
                    // that only read top-level nodes would never see it.
                    $nested = $config['graph']['nodes'] ?? null;
                    if (is_array($nested)) {
                        $found = [...$found, ...self::references($nested)];
                    }
                    break;
            }
        }

        return $found;
    }

    /**
     * Kind ids are namespaced (`@particle-academy/subflow`) with bare aliases
     * (`subflow`), and graphs in the wild carry both. Matching one spelling would
     * silently stop seeing half of them.
     */
    private static function kindName(string $type): string
    {
        $slash = strrpos($type, '/');

        return $slash === false ? $type : substr($type, $slash + 1);
    }

    /** Mirrors the executor's pin rules; anything unusable is treated as unpinned. */
    private static function version(mixed $pin): ?int
    {
        if ($pin === null || $pin === '') {
            return null;
        }
        if (is_int($pin)) {
            return $pin;
        }

        return ctype_digit((string) $pin) ? (int) $pin : null;
    }

    /**
     * A pinned ref is a DIFFERENT interface from the same ref unpinned, or from
     * another pin of it — so `A@1 → A@2` is not a loop on its own. Collapsing
     * them would refuse a legitimate "call the previous version" graph.
     */
    private static function key(string $ref, ?int $version): string
    {
        return $version === null ? $ref : "$ref@$version";
    }
}
