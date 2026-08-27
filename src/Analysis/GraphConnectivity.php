<?php

declare(strict_types=1);

namespace FancyFlow\Analysis;

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\ImportIssue;

/**
 * Refuse a graph whose nodes cannot take part in the workflow's dataflow.
 *
 * Two shapes, both of which import cleanly today and then quietly do nothing.
 * Neither FAILS -- which is what makes them worth refusing at authoring time,
 * because a run that reports success is the worst way for a workflow to be
 * wrong. Both were measured against the engine before this class was written,
 * not assumed from reading it:
 *
 * ## 1. A FLOATING node -- no inbound and no outbound edge
 *
 * It is NOT skipped. A node with no incoming edge is a root, so the topo sort
 * runs it: a three-node graph with one floating `log` executed `t,lonely,o`.
 * It runs disconnected -- receiving nothing from the graph and reaching nobody
 * in it -- which is exactly the state an author cannot see on a canvas. Either
 * someone forgot to wire it, or a deletion left it behind.
 *
 * **`note` is the one kind allowed to float**, and that is less an exception
 * than the definition of it: an annotation is a comment on the canvas, the
 * engine never executes it, and requiring it to be wired would make it a node.
 *
 * ## 2. An edge leaving a TERMINATOR
 *
 * A terminal kind -- `output`, `log` -- declares an EMPTY output port list. It
 * ends a chain. Measured: `t -> output -> log` imports clean, and the `log`
 * DOES run -- with `{{ input }}` resolving to `""`. Nothing errors, nothing
 * warns at the author, and the node downstream simply operates on a hole.
 *
 * That is the same silent-nothing the undelivered-edge diagnostic reports at
 * run time -- but this one is decidable FROM THE DOCUMENT ALONE, without
 * running anything, so it should never reach a run to be diagnosed.
 *
 * ## Why these are errors and not warnings
 *
 * Both are unambiguous. There is no data at run time that makes a floating node
 * participate, and none that makes an edge out of a terminator deliver. That is
 * the test for refusing at authoring time rather than warning: a warning is for
 * something that MIGHT be intended, and neither of these can be.
 */
final class GraphConnectivity
{
    /**
     * @param  list<FlowNode> $nodes
     * @param  list<FlowEdge> $edges
     * @return list<ImportIssue>
     */
    public static function check(array $nodes, array $edges, ?NodeKindRegistry $registry = null): array
    {
        $registry ??= NodeKindRegistry::default();

        $hasIncoming = [];
        $hasOutgoing = [];

        foreach ($edges as $edge) {
            $hasIncoming[$edge->target] = true;
            $hasOutgoing[$edge->source] = true;
        }

        $issues = [];

        // A single-node graph is not "floating" -- it is a graph with one step,
        // which is a legitimate (if small) workflow and something an author
        // builds on the way to a bigger one. Refusing it would make the editor
        // unusable from the first node placed.
        $single = count($nodes) === 1;

        foreach ($nodes as $node) {
            if (self::mayFloat($node)) {
                continue;
            }

            if ($single) {
                continue;
            }

            if (! isset($hasIncoming[$node->id]) && ! isset($hasOutgoing[$node->id])) {
                $issues[] = ImportIssue::error(
                    sprintf(
                        'Node "%s" is connected to nothing — no inbound edge and no outbound edge. It still RUNS '
                        .'(a node with no inbound edge is a root), but it receives nothing from the graph '
                        .'and reaches nobody in it, so it is either unwired or left behind by a deletion. '
                        .'Only a `note` may float.',
                        $node->id,
                    ),
                    nodeId: $node->id,
                );
            }
        }

        $byId = [];
        foreach ($nodes as $node) {
            $byId[$node->id] = $node;
        }

        foreach ($edges as $edge) {
            $source = $byId[$edge->source] ?? null;

            if ($source === null || ! self::isTerminator($source, $registry)) {
                continue;
            }

            $issues[] = ImportIssue::error(
                sprintf(
                    'Edge "%s" reads from "%s", which is a TERMINAL node and publishes no output ports at '
                    .'all. Nothing can ever travel this edge: it does not fail at run time, it delivers '
                    .'nothing, and "%s" runs anyway with an empty input.',
                    $edge->id,
                    $edge->source,
                    $edge->target,
                ),
                edgeId: $edge->id,
            );
        }

        return $issues;
    }

    /**
     * Only an annotation may sit unconnected.
     *
     * Matched across every id the kind answers to — a graph saved with the
     * canonical `@particle-academy/note` must stay an annotation rather than
     * becoming an unwireable node, which is the same aliasing rule the engine
     * and the executors already follow.
     */
    private static function mayFloat(FlowNode $node): bool
    {
        return $node->type !== null && KindId::matches($node->type, 'note');
    }

    /**
     * A kind that declares an EMPTY output list ends a chain.
     *
     * `[]` and `null` are different answers and only the first one means this.
     * `null` is "nobody declared what this publishes", which resolves to `out`
     * and is a perfectly ordinary node; `[]` is an explicit claim that there is
     * nothing to connect from. Treating them alike here would refuse most of
     * the graphs anyone writes.
     */
    private static function isTerminator(FlowNode $node, NodeKindRegistry $registry): bool
    {
        // A node declaring its own outputs overrides its kind, so an author who
        // has said what this node publishes is believed.
        if ($node->outputs !== null) {
            return $node->outputs === [];
        }

        $kindName = $node->kind();
        $kind = $kindName !== null ? $registry->get($kindName) : null;

        if ($kind === null) {
            // An unregistered kind publishes `out` by the engine's fallback, so
            // it is not a terminator. Refusing an edge from a kind we simply do
            // not know about would break every host mid-registration.
            return false;
        }

        // The DECLARATION alone, deliberately.
        //
        // `PortResolution::possible()` cannot be used as a second condition
        // here: it returns `['out']` for a kind declaring `[]`, because that is
        // the engine's historical activation fallback -- so testing it would
        // make this method always return false and the whole check dead. Caught
        // before wiring, by reading what that helper actually returns rather
        // than what its name suggests.
        //
        // The two answer different questions, which is the same split the MCP's
        // PortResolver keeps: "what may I connect FROM?" (authoring, this) and
        // "what does it publish at run time?" (activation, that).
        return $kind->outputs === [];
    }
}
