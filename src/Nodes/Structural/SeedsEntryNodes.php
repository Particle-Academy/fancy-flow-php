<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Structural;

use FancyFlow\Schema\FlowGraph;

/**
 * Hands a parent node's inputs to a child graph's entry points.
 *
 * Shared by the two nesting executors — {@see SubgraphExecutor} (child graph
 * embedded in config) and {@see SubflowExecutor} (child graph named and
 * resolved by the host). They differ in where the graph comes from, not in how
 * it gets its inputs, so this stays one implementation.
 */
trait SeedsEntryNodes
{
    /**
     * Seed every entry node (one with no incoming edge) with `$inputs`.
     *
     * @param array<string,mixed> $inputs
     * @return array<string,array<string,mixed>>
     */
    private function seedEntryNodes(FlowGraph $graph, array $inputs): array
    {
        $hasIncoming = [];
        foreach ($graph->edges as $edge) {
            $hasIncoming[$edge->target] = true;
        }

        $seed = [];
        foreach ($graph->nodes as $node) {
            if (! isset($hasIncoming[$node->id])) {
                $seed[$node->id] = $inputs;
            }
        }

        return $seed;
    }
}
