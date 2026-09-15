<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\FlowGraph;

/**
 * Run ONE node of a graph — through the real engine, not around it.
 *
 * ## The problem this solves
 *
 * A per-node driver has to hand a node exactly the inputs it would have
 * received mid-run: the right values, on the right target handles, from the
 * right *active* edges. Those rules are the engine's (`collectInputs`,
 * `activatedPorts`, the merge-after-decision contract, the `out` fallbacks), and
 * they are the reason the PHP and TS runtimes agree. Re-implementing them here
 * would be a second engine wearing a driver's clothes — and the two would drift.
 *
 * ## What it does instead
 *
 * It replays the graph with {@see \FancyFlow\Engine\FlowRunner} untouched:
 *
 *  - every node already completed is fed back as `resumeOutputs`, so the engine
 *    republishes it on the same ports and routes exactly as it did the first
 *    time;
 *  - every node EXCEPT the target is bound, by node id, to a FENCE that runs
 *    nothing and publishes only a port no edge reads;
 *  - so the engine walks its own topological order, skips its own dead branches,
 *    collects the target's inputs its own way, and runs the target.
 *
 * ## Why the fence does not stop the walk
 *
 * It used to abort the run. The target's own inputs never depend on a fenced
 * node -- the frontier dispatches a node only once every source is settled, and
 * settled sources are resumed, not fenced -- but an UNRELATED node can precede
 * the target in topological order. Two siblings dispatched together are exactly
 * that: when `b`'s job started while `a` was still running, the replay aborted
 * at `a`, never reached `b`, and RunNodeJob read "the replay ended without
 * running me" as "the engine decided I am unreachable". `b` was recorded
 * skipped, never ran, and the run completed as a success. A queue with one
 * worker, and the sync queue every test used, can never produce that order.
 *
 * Walking past fences makes that inference honest again: when the replay
 * finishes without an output for the target, it is because the engine found
 * every inbound edge dead.
 *
 * The target's output is `$result->outputs[$nodeId]`, and the ports it activated
 * arrive as the engine's own `node-output` events. Nothing about routing is
 * recomputed here.
 *
 * ## The cost, stated plainly
 *
 * Replaying the completed prefix is O(nodes) per node, so a run is O(nodes²) in
 * bookkeeping. The republish executes nothing — it re-publishes stored values —
 * so for the graph sizes workflows actually have this is noise next to a single
 * queue round trip. It buys exact fidelity to the engine, which is not
 * negotiable, and one implementation of the routing rules instead of two.
 */
final class GraphReplay
{
    /**
     * The abort reason a boundary used to report. Nothing aborts with it any
     * more (see "Why the fence does not stop the walk"); {@see isBoundary()}
     * still recognises it so a caller that checks for it keeps working.
     */
    public const BOUNDARY = 'fancy-flow:node-boundary';

    /**
     * The port a fenced node publishes on. No edge reads it, so everything
     * downstream of a fenced node is dark in the replay -- which never matters to
     * the target, whose sources are all settled.
     */
    public const FENCE_PORT = 'fancy-flow:fenced';

    /**
     * Replay `$graph` up to and through `$nodeId`.
     *
     * Pass `$nodeId = null` to PROBE: every node is a boundary, so nothing
     * executes and the engine reports only what it can determine structurally —
     * a cycle, and the ports each resumed output republishes on.
     *
     * @return array{result:RunResult,ports:array<string,list<string>>}
     */
    public static function upTo(
        FancyFlowManager $flow,
        FlowGraph $graph,
        ?string $nodeId,
        ExecutorRegistry $executors,
        RunOptions $options,
        string $runId,
        ?callable $onTargetContext = null,
    ): array {
        $boundary = static fn (ExecutionContext $ctx): array => Port::only(self::FENCE_PORT);

        $fork = $executors->fork();
        foreach ($graph->nodes as $node) {
            if ($node->id !== $nodeId) {
                // bindNode outranks kind bindings AND the `*` fallback, so this
                // fences off the whole graph regardless of what a host bound.
                $fork->bindNode($node->id, $boundary);
            }
        }

        // This wrapper sees the ExecutionContext AFTER FlowRunner has resolved
        // the real inputs and BEFORE the executor can perform a side effect.
        // Capturing here keeps collectInputs() the one routing implementation.
        if ($nodeId !== null && $onTargetContext !== null) {
            $target = $graph->node($nodeId);
            $targetExecutor = $target === null ? null : $executors->resolveFor($target);

            if ($targetExecutor !== null) {
                $fork->bindNode($nodeId, static function (ExecutionContext $ctx) use ($onTargetContext, $targetExecutor): mixed {
                    $onTargetContext($ctx);

                    return $targetExecutor($ctx);
                });
            }
        }

        /** @var array<string,list<string>> $ports */
        $ports = [];
        $collect = static function (RunEvent $event) use (&$ports): void {
            if ($event->type === RunEvent::NODE_OUTPUT) {
                $ports[(string) $event->nodeId][] = (string) $event->portId;
            }
        };

        $result = $flow->run(
            $graph,
            onEvent: $collect,
            options: $options,
            runId: $runId,
            executors: $fork,
            emitTerminalEvents: false,
            // Republished nodes are not news; a probe has nothing to announce.
            eventNodes: $nodeId === null ? [] : [$nodeId],
        );

        return ['result' => $result, 'ports' => $ports];
    }

    /** True when a run ended because the replay reached a node it does not own. */
    public static function isBoundary(?string $error): bool
    {
        return $error === self::BOUNDARY;
    }
}
