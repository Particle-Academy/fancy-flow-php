<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Schema\FlowNode;

/**
 * How many times a single node may be attempted.
 *
 * ## Why this cannot be one number
 *
 * `queue.tries` covered an entire graph, which forced every workflow to pick
 * between two bad answers. At 1, a single flaky LLM or HTTP call takes the whole
 * run down — reported independently by the Moic Suite integration. Above 1, the
 * retry replays from the last checkpoint, and everything already done runs
 * again, including the nodes that must not: `git_pr_open` opens a second pull
 * request.
 *
 * Per-node jobs make the question per node, which is where it always belonged.
 * A node declaring `sideEffects: unsafe-to-replay` is pinned to ONE attempt and
 * no backoff — the manifest has been able to say this since 0.6, and until now
 * nothing could act on it. Everything else takes the configured tries, or a
 * per-kind override.
 *
 * Undeclared side effects are treated as the configured default rather than
 * assumed safe: this decides retries, and inventing a safety claim on a node
 * author's behalf is how a retry loop ends up posting the same webhook twice.
 */
final class NodeRetryPolicy
{
    /** A node that is not safe to run twice. Same vocabulary as the node manifest. */
    public const UNSAFE = 'unsafe-to-replay';

    /** @param array<string,mixed> $config the `fancy-flow` config array */
    public static function tries(FlowNode $node, NodeKindRegistry $kinds, array $config): int
    {
        if (self::isUnsafeToReplay($node, $kinds)) {
            return 1;
        }

        $overrides = (array) ($config['queue']['node_tries'] ?? []);
        foreach (self::ids($node, $kinds) as $id) {
            if (array_key_exists($id, $overrides)) {
                return max(1, (int) $overrides[$id]);
            }
        }

        return max(1, (int) ($config['queue']['tries'] ?? 1));
    }

    /** @param array<string,mixed> $config */
    public static function backoff(FlowNode $node, NodeKindRegistry $kinds, array $config): int
    {
        // Nothing to back off from: the node gets one attempt.
        if (self::isUnsafeToReplay($node, $kinds)) {
            return 0;
        }

        return max(0, (int) ($config['queue']['backoff'] ?? 0));
    }

    public static function isUnsafeToReplay(FlowNode $node, NodeKindRegistry $kinds): bool
    {
        if ($node->type === null) {
            return false;
        }

        return $kinds->get($node->type)?->sideEffects === self::UNSAFE;
    }

    /**
     * Every id a `node_tries` override for this node could be keyed under.
     *
     * Canonical ids are namespaced while a host almost certainly writes the bare
     * one; keying on only the literal string would make the override silently
     * stop applying the day a kind is renamed.
     *
     * @return list<string>
     */
    private static function ids(FlowNode $node, NodeKindRegistry $kinds): array
    {
        if ($node->type === null) {
            return [];
        }

        return array_values(array_unique([
            $node->type,
            ...$kinds->idsFor($node->type),
            ...KindId::variants($node->type),
        ]));
    }
}
