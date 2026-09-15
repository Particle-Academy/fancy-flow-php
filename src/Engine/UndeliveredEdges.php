<?php

declare(strict_types=1);

namespace FancyFlow\Engine;

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\PortResolution;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowNode;

/**
 * AN EDGE THAT DELIVERS NOTHING MUST SAY SO -- the rule, in one place.
 *
 * An edge whose source COMPLETED without publishing the port it reads, and
 * which could NEVER publish it, binds nothing on any run. If it is the target's
 * only inbound edge the target is skipped; if not, the target runs with that
 * input missing and a correct template renders empty. Both are silent, so the
 * engine warns against the target.
 *
 * ## Why it is its own class
 *
 * {@see FlowRunner} asks this as it reaches each node. The `per_node` driver
 * has to ask it too, at a different moment: every node job replays the graph
 * and forwards only its own node's events, so a target that is SKIPPED -- which
 * never gets a job -- was noticed inside some other node's replay and filtered
 * out, on every run. The driver now asks when `AdvanceWorkflowJob` records the
 * skip, from the ports stored on the claim rows (which are the engine's own
 * `node-output` events). One rule, two callers; a second copy would agree right
 * up until someone edited one of them.
 *
 * Pinned by fancy-conformance `flow/run-diagnostics`, which the durable suite
 * also runs through both queue drivers.
 */
final class UndeliveredEdges
{
    /**
     * The warnings for `$target`'s inbound edges, as `log`/`warn` events.
     *
     * @param  list<FlowEdge>  $incoming  edges whose target is `$target`
     * @param  array<string,mixed>  $portValues  keyed `"<node>:<port>"`, in publication order
     * @param  array<string,bool>  $completed  node id => true for every node that completed
     * @param  array<string,FlowNode>  $nodesById
     * @return list<RunEvent>
     */
    public static function warnings(
        FlowNode $target,
        array $incoming,
        array $portValues,
        array $completed,
        array $nodesById,
        ?NodeKindRegistry $kinds,
    ): array {
        $warnings = [];

        // Keyed on the source having COMPLETED, which is what separates the two
        // reasons a key can be absent. A branch that was not taken is ordinary
        // and must never warn; a source that finished and does not publish this
        // port is a misconfiguration that will never work on any run. A warning
        // that fires on ordinary branching is noise, and noise is how a real
        // warning stops being read.
        foreach ($incoming as $edge) {
            $handle = $edge->sourceHandle ?? 'out';

            if (array_key_exists($edge->source.':'.$handle, $portValues)
                || ! ($completed[$edge->source] ?? false)
                || in_array($handle, self::possiblePortIds($nodesById[$edge->source] ?? null, $kinds), true)) {
                continue;
            }

            $warnings[] = RunEvent::log(
                'warn',
                self::message($edge, $target, $portValues, $kinds, $nodesById),
                $target->id,
                ['edge' => $edge->id, 'source' => $edge->source, 'sourceHandle' => $handle],
            );
        }

        return $warnings;
    }

    /**
     * The message for an edge whose source port publishes nothing.
     *
     * Shape agreed with the consumer who reported the defect, in their order,
     * and each part earns its place:
     *
     *   1. THE EDGE ID FIRST. The author is looking at a graph, and the edge is
     *      the thing they can act on. Naming only the nodes makes them hunt for
     *      which of several edges is meant.
     *   2. THE CONSEQUENCE, IN RUNTIME TERMS. Without "nothing will reach X"
     *      this reads as a schema nit, and the author's instinct is that a
     *      handle string is cosmetic. It is the difference between a lint and a
     *      silently empty document.
     *   3. THE AVAILABLE PORTS -- what makes it actionable rather than merely
     *      correct. Taken from what the source ACTUALLY published on this run,
     *      not from the kind's declaration, so a config-driven kind reports its
     *      real ports.
     *   4. THE REMEDY FOR THE COMMON CASE. Nearly every occurrence is an agent
     *      ADDING a handle that should not be there, rather than choosing the
     *      wrong one of several -- so "leave sourceHandle off" is the fix more
     *      often than picking from the list.
     *
     * Plus the part only the engine can supply: when the named handle is a
     * near-miss for a FIELD the source emits, say so. That is the actual
     * confusion -- an agent reaching for a field name where a port belongs --
     * and naming it turns a correction into an explanation.
     *
     * @param  array<string,mixed>  $portValues
     * @param  array<string,FlowNode>  $graphNodes
     */
    private static function message(
        FlowEdge $edge,
        FlowNode $target,
        array $portValues,
        ?NodeKindRegistry $kinds,
        array $graphNodes,
    ): string {
        $handle = $edge->sourceHandle ?? 'out';

        $prefix = $edge->source.':';
        $available = [];
        foreach (array_keys($portValues) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                $available[] = substr((string) $key, strlen($prefix));
            }
        }

        $message = sprintf(
            'Edge %s reads port "%s" from node %s, which never publishes it — nothing would reach %s at run time.',
            $edge->id,
            $handle,
            $edge->source,
            $target->id,
        );

        if ($available !== []) {
            $message .= ' Available: '.implode(', ', $available).'.';
        }

        // The near-miss: a FIELD of that name, where a PORT was expected.
        $sourceNode = $graphNodes[$edge->source] ?? null;
        $kindName = $sourceNode?->kind();
        $kind = ($kindName !== null && $kinds !== null) ? $kinds->get($kindName) : null;
        $fields = $kind?->outputShapeFor($sourceNode?->config ?? []) ?? null;

        if (is_array($fields)) {
            foreach ($fields as $field) {
                $path = is_array($field) ? ($field['path'] ?? null) : null;
                if ($path === $handle) {
                    $message .= sprintf(
                        ' Note: "%s" is a FIELD this node emits, not a port — read it downstream as {{ in.%s }} rather than naming it as a source handle.',
                        $handle,
                        $handle,
                    );
                    break;
                }
            }
        }

        if ($edge->sourceHandle !== null) {
            $message .= " Leave sourceHandle off to read the node's output.";
        }

        return $message;
    }

    /**
     * Every port this node COULD publish — not the ones it did.
     *
     * The distinction that keeps the undelivered-edge warning honest. A `branch`
     * that took `true` publishes no `false`, and the edge leaving `false` binds
     * nothing: that is ORDINARY BRANCHING and must never warn. A handle that is
     * not a port of the node at all can never bind on any run, and that is a
     * misconfiguration worth saying out loud.
     *
     * Asking "did it publish?" cannot tell those apart — both are absent — so it
     * would warn on every branching graph. **A warning that fires on ordinary
     * branching is noise, and noise is how a real warning stops being read.**
     *
     * Delegated to {@see PortResolution} so the ENGINE and the AUTHORING API
     * cannot disagree. They did: `fancy-flow-mcp` derived a `switch_case`'s
     * ports from its `cases` config and correctly offered a third case, while
     * the engine read only the kind's static declaration and reported that same
     * port as impossible. The authoring API invited an edge and the runtime
     * called it a mistake.
     *
     * @return list<string>
     */
    private static function possiblePortIds(?FlowNode $node, ?NodeKindRegistry $kinds): array
    {
        if ($node === null) {
            return ['out'];
        }

        $kindName = $node->kind();
        $kind = ($kindName !== null && $kinds !== null) ? $kinds->get($kindName) : null;

        return PortResolution::possible($node, $kind, $node->config ?? []);
    }
}
