<?php

declare(strict_types=1);

namespace FancyFlow\Engine;

use Closure;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\NodeStatus;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;
use Throwable;

/**
 * Topological execution of a {@see FlowGraph} against an {@see ExecutorRegistry}
 * — the PHP port of fancy-flow's `runFlow`.
 *
 * Each node runs once, in a Kahn topological order. A node executes when at
 * least one incoming edge is active (its source port produced a value); this is
 * the fix for the merge-after-decision bug (#1) — requiring *all* incoming
 * edges to be active wrongly skipped a shared continuation after a Decision
 * routed down one branch. Cycles are detected and abort the run.
 *
 * Port activation follows three conventions on an executor's result:
 *   1. `['__port' => 'x', 'value' => …]` → only port `x` emits.
 *   2. `['branch' => 'x', 'value' => …]` → only port `x` emits (Decision sugar).
 *   3. anything else → the value is published on every declared output port.
 *
 * @see \FancyFlow\Runtime\Port for the branching helpers.
 */
final class FlowRunner
{
    /**
     * @param (callable(RunEvent):void)|null $onEvent
     */
    public function run(
        FlowGraph $graph,
        ExecutorRegistry $executors,
        ?callable $onEvent = null,
        ?RunOptions $options = null,
    ): RunResult {
        $options ??= new RunOptions();
        $initialInputs = $options->initialInputs;
        $resumeOutputs = $options->resumeOutputs;
        $signal = $options->signal;
        $timeoutMs = $options->timeoutMs;

        /** @var array<string,mixed> $outputs collected per node, keyed by node id. */
        $outputs = [];
        /** @var array<string,mixed> $portValues key: "{nodeId}:{portId}". */
        $portValues = [];
        /** @var array<string,bool> $completed */
        $completed = [];
        /** @var list<string> $errors */
        $errors = [];
        /** @var list<RunEvent> $events */
        $events = [];

        $emit = static function (RunEvent $event) use (&$events, $onEvent): void {
            $events[] = $event;
            if ($onEvent !== null) {
                $onEvent($event);
            }
        };

        // Deterministic topological order; also our cycle check.
        $order = $this->topoSort($graph);
        if ($order === null) {
            $msg = 'Cycle detected in flow graph — aborting.';
            $emit(RunEvent::runError($msg));

            return new RunResult(false, $outputs, $msg, $events);
        }

        $incomingByNode = $this->indexIncoming($graph->edges);
        $start = hrtime(true);

        $emit(RunEvent::runStart());

        foreach ($order as $node) {
            // Host cancellation propagates (matches TS: signal abort throws out of
            // the run — distinct from an executor's abort(), which returns ok:false).
            if ($signal !== null && $signal->aborted()) {
                throw new RunAborted($signal->reason ?? 'aborted');
            }

            // A timeout is registered as an error and caught here between nodes,
            // mirroring the TS timer that pushes an error the loop then observes.
            if ($timeoutMs !== null && $errors === [] && $this->elapsedMs($start) > $timeoutMs) {
                $errors[] = "Run timed out after {$timeoutMs}ms";
            }
            if ($errors !== []) {
                break;
            }

            // Resume: a node completed in a prior run is not re-executed — its
            // stored output is republished on its ports (reproducing the same
            // routing) so downstream nodes see identical inputs.
            if (array_key_exists($node->id, $resumeOutputs)) {
                $this->publish($node, $resumeOutputs[$node->id], $outputs, $portValues, $completed, $emit, resumed: true);

                continue;
            }

            $incoming = $incomingByNode[$node->id] ?? [];

            // Run once any upstream branch reaches this node. In topo order every
            // upstream node is already settled, so each incoming edge is active or
            // dead — never pending. Requiring ALL active wrongly skipped merge
            // points (#1); collectInputs() only reads the active ones.
            if ($incoming !== []) {
                $anyActive = false;
                foreach ($incoming as $edge) {
                    if (array_key_exists($this->portKey($edge->source, $edge->sourceHandle), $portValues)) {
                        $anyActive = true;
                        break;
                    }
                }
                if (! $anyActive) {
                    $emit(RunEvent::nodeStatus($node->id, NodeStatus::IDLE, 'skipped'));

                    continue;
                }
            }

            // Note nodes are annotations — never executed.
            if ($node->type === 'note') {
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::IDLE, 'annotation'));

                continue;
            }

            $emit(RunEvent::nodeStatus($node->id, NodeStatus::RUNNING));

            $inputs = $this->collectInputs($node, $incoming, $portValues, $initialInputs);
            $exec = $executors->resolveFor($node);
            if ($exec === null) {
                $msg = "No executor registered for kind={$node->type}";
                $errors[] = $msg;
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::ERROR, $msg));
                $emit(RunEvent::log('error', $msg, $node->id));

                break;
            }

            try {
                $ctx = new ExecutionContext($node, $inputs, Closure::fromCallable($emit));
                $result = $exec($ctx);
                $this->publish($node, $result, $outputs, $portValues, $completed, $emit);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = $msg;
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::ERROR, $msg));
                $emit(RunEvent::log('error', $msg, $node->id));

                break;
            }
        }

        $ok = $errors === [];
        $emit(RunEvent::runEnd($ok));

        return new RunResult($ok, $outputs, $ok ? null : $errors[0], $events);
    }

    /**
     * Record a node's result: store it, publish it on the activated ports, and
     * mark it done. Shared by normal execution and resume.
     *
     * @param array<string,mixed> $outputs
     * @param array<string,mixed> $portValues
     * @param array<string,bool>  $completed
     */
    private function publish(
        FlowNode $node,
        mixed $result,
        array &$outputs,
        array &$portValues,
        array &$completed,
        callable $emit,
        bool $resumed = false,
    ): void {
        $outputs[$node->id] = $result;

        $activated = $this->activatedPorts($node, $result);
        foreach ($activated['ports'] as $portId) {
            $portValues[$this->portKey($node->id, $portId)] = $activated['value'];
            $emit(RunEvent::nodeOutput($node->id, $portId, $activated['value']));
        }

        $completed[$node->id] = true;
        $emit(RunEvent::nodeStatus($node->id, NodeStatus::DONE, $resumed ? 'resumed' : null));
    }

    /**
     * @param list<FlowEdge> $edges
     * @return array<string, list<FlowEdge>>
     */
    private function indexIncoming(array $edges): array
    {
        $map = [];
        foreach ($edges as $edge) {
            $map[$edge->target][] = $edge;
        }

        return $map;
    }

    /**
     * Kahn's algorithm. Returns nodes in a deterministic topological order, or
     * null when a cycle is present. Iteration order matches the TS engine so
     * runs are byte-for-byte comparable.
     *
     * @return list<FlowNode>|null
     */
    private function topoSort(FlowGraph $graph): ?array
    {
        $inDegree = [];
        foreach ($graph->nodes as $node) {
            $inDegree[$node->id] = 0;
        }
        foreach ($graph->edges as $edge) {
            $inDegree[$edge->target] = ($inDegree[$edge->target] ?? 0) + 1;
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $ordered = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $ordered[] = $id;
            foreach ($graph->edges as $edge) {
                if ($edge->source !== $id) {
                    continue;
                }
                $next = ($inDegree[$edge->target] ?? 0) - 1;
                $inDegree[$edge->target] = $next;
                if ($next === 0) {
                    $queue[] = $edge->target;
                }
            }
        }

        if (count($ordered) !== count($graph->nodes)) {
            return null;
        }

        $byId = [];
        foreach ($graph->nodes as $node) {
            $byId[$node->id] = $node;
        }

        $out = [];
        foreach ($ordered as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * Gather a node's inputs, keyed by target-port id (default `in`), seeded
     * with any initial inputs.
     *
     * Only *active* incoming edges contribute — this is the contract the TS
     * engine documents ("collectInputs() only reads from the active ones") as
     * part of the merge-after-decision fix (#1). We implement that contract
     * directly: an edge whose source port never produced a value (a dead branch)
     * is skipped, so it can't clobber a live value arriving on the same port.
     * The TS *code* assigns unconditionally, which lets a trailing dead edge
     * overwrite a live one with `undefined`; skipping keeps the value the fix
     * was meant to deliver, and matches TS in every case where a port has at
     * most one active source.
     *
     * @param list<FlowEdge>                    $incoming
     * @param array<string,mixed>               $portValues
     * @param array<string,array<string,mixed>> $initial
     * @return array<string,mixed>
     */
    private function collectInputs(FlowNode $node, array $incoming, array $portValues, array $initial): array
    {
        $inputs = $initial[$node->id] ?? [];
        foreach ($incoming as $edge) {
            $key = $this->portKey($edge->source, $edge->sourceHandle);
            if (array_key_exists($key, $portValues)) {
                $inputs[$edge->targetHandle ?? 'in'] = $portValues[$key];
            }
        }

        return $inputs;
    }

    /**
     * Decide which output ports an executor's result activates, and the value
     * carried. Faithful to the TS `activatedPorts`.
     *
     * @return array{ports:list<string>,value:mixed}
     */
    private function activatedPorts(FlowNode $node, mixed $result): array
    {
        if (is_array($result)) {
            if (isset($result['__port']) && is_string($result['__port'])) {
                return ['ports' => [$result['__port']], 'value' => $result['value'] ?? null];
            }
            if (isset($result['branch']) && is_string($result['branch'])) {
                return ['ports' => [$result['branch']], 'value' => $result['value'] ?? $result];
            }
        }

        // Declared output ports, or a single `out`. An explicitly-empty array
        // yields zero ports.
        //
        // When the node declares none, fall back to the KIND's ports before
        // falling back to `out`. The TS side resolves ports through its kind
        // (including config-driven kinds like `switch_case`, whose ports come
        // from its `cases` map), and it now serializes the resolved ports into
        // the document. This fallback covers hand-written schemas that omit
        // them: without it a branch node collapses to a single `out` here while
        // routing correctly on Node, breaking the same-JSON-same-outputs
        // guarantee this port exists to uphold.
        $declared = $node->outputs;
        $kindName = $node->kind();
        if ($declared === null && $kindName !== null) {
            $kindPorts = NodeKindRegistry::default()->get($kindName)?->outputs;
            // Only adopt NON-EMPTY kind ports. A terminal kind (category
            // "output") declares an empty list, and consuming that literally
            // would publish zero ports where the historical fallback published
            // `out` — silently cutting every chain through such a node.
            if ($kindPorts !== null && $kindPorts !== []) {
                $declared = $kindPorts;
            }
        }

        if ($declared === null) {
            $ports = ['out'];
        } else {
            $ports = array_map(static fn (PortDescriptor $p) => $p->id, $declared);
        }

        return ['ports' => $ports, 'value' => $result];
    }

    private function portKey(string $nodeId, ?string $portId): string
    {
        return $nodeId.':'.($portId ?? 'out');
    }

    private function elapsedMs(int|float $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }
}
