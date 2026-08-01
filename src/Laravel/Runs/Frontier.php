<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Registry\KindId;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;

/**
 * Which nodes can run RIGHT NOW, given what has already settled.
 *
 * ## Why this is not a second engine
 *
 * {@see \FancyFlow\Engine\FlowRunner} walks a Kahn topological order and, at
 * each node, runs it when at least one incoming edge is active. That is a total
 * order because one process executes every node. Split the graph across jobs and
 * the same rule has to be asked the other way round — not "what is next" but
 * "what is unblocked" — which is this class.
 *
 * The rule is the engine's, restated:
 *
 *  - every direct predecessor has SETTLED (in topo order the engine has already
 *    settled all of them by the time it reaches a node);
 *  - and either the node has no incoming edges, or at least one incoming edge is
 *    ACTIVE — its source completed and published on that edge's source handle.
 *
 * A node whose predecessors have all settled with no active edge is what the
 * engine reports as `idle/skipped`. Skipping settles it, which can unblock — or
 * skip — its own successors, so the pass repeats until nothing changes.
 *
 * ## The one thing it does NOT decide
 *
 * Which ports a result activated. Those rules (`__port`, `branch`, declared
 * outputs, the kind's ports, the `out` fallback) live in the engine and stay
 * there: the driver reads the ports back off the `node-output` events the engine
 * emitted when the node ran, stored on the claim row. A second copy of that
 * routing table is exactly the kind of duplicate that agrees for a year and then
 * quietly disagrees on one branch.
 */
final class Frontier
{
    /**
     * @param  array<string,array{status:string,ports:list<string>}>  $state  from {@see NodeClaims::state()}
     * @return array{ready:list<string>,skipped:list<string>}
     */
    public static function compute(FlowGraph $graph, array $state): array
    {
        /** @var array<string,list<FlowEdge>> $incoming */
        $incoming = [];
        foreach ($graph->edges as $edge) {
            $incoming[$edge->target][] = $edge;
        }

        // Settled nodes and the ports they lit. A skipped node is settled with
        // no ports, which is precisely what makes its successors skip too.
        /** @var array<string,list<string>> $settled */
        $settled = [];
        /** @var array<string,true> $held nodes a worker holds, or that are parked/failed */
        $held = [];
        foreach ($state as $nodeId => $entry) {
            if ($entry['status'] === WorkflowRunNode::COMPLETED) {
                $settled[$nodeId] = $entry['ports'];
            } elseif ($entry['status'] === WorkflowRunNode::SKIPPED) {
                $settled[$nodeId] = [];
            } else {
                $held[$nodeId] = true;
            }
        }

        /** @var array<string,true> $ready */
        $ready = [];
        $skipped = [];

        do {
            $changed = false;

            foreach ($graph->nodes as $node) {
                $id = $node->id;
                if (isset($settled[$id]) || isset($held[$id]) || isset($ready[$id])) {
                    continue;
                }

                $edges = $incoming[$id] ?? [];
                $blocked = false;
                $active = false;

                foreach ($edges as $edge) {
                    if (! array_key_exists($edge->source, $settled)) {
                        $blocked = true;
                        break;
                    }
                    // The engine's port key: an edge with no source handle reads
                    // the source's `out` port.
                    if (in_array($edge->sourceHandle ?? 'out', $settled[$edge->source], true)) {
                        $active = true;
                    }
                }

                if ($blocked) {
                    continue;
                }

                // Reached, but down a branch that never lit.
                if ($edges !== [] && ! $active) {
                    $settled[$id] = [];
                    $skipped[] = $id;
                    $changed = true;

                    continue;
                }

                // Annotations are never executed. Settling them here rather than
                // dispatching a job saves a queue round trip per sticky note —
                // and a graph can carry a lot of sticky notes.
                if ($node->type !== null && KindId::matches($node->type, 'note')) {
                    $settled[$id] = [];
                    $skipped[] = $id;
                    $changed = true;

                    continue;
                }

                $ready[$id] = true;
            }
        } while ($changed);

        return ['ready' => array_keys($ready), 'skipped' => $skipped];
    }

    /**
     * Has every node in the graph settled? The run is finished when it has.
     *
     * @param  array<string,array{status:string,ports:list<string>}>  $state
     */
    public static function isComplete(FlowGraph $graph, array $state): bool
    {
        foreach ($graph->nodes as $node) {
            $status = $state[$node->id]['status'] ?? null;
            if (! in_array($status, WorkflowRunNode::SETTLED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is any node still held by a worker (claimed) or parked?
     *
     * An empty frontier means something different depending on this: with work
     * in flight the run is simply waiting, and whichever job finishes will
     * advance it. With nothing in flight and nodes still unsettled, the graph
     * cannot progress at all.
     *
     * @param  array<string,array{status:string,ports:list<string>}>  $state
     */
    public static function hasWorkInFlight(array $state): bool
    {
        foreach ($state as $entry) {
            if (in_array($entry['status'], [WorkflowRunNode::CLAIMED, WorkflowRunNode::PAUSED], true)) {
                return true;
            }
        }

        return false;
    }
}
