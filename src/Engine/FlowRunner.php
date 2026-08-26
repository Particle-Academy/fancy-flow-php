<?php

declare(strict_types=1);

namespace FancyFlow\Engine;

use Closure;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Exceptions\NodeExecutionException;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\NodeStatus;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\WorkflowProps;
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

        // Props are checked BEFORE anything runs, and a failure aborts.
        //
        // Before a node executes, not after: a workflow whose third node needs
        // a value the caller misspelled would otherwise do two nodes' worth of
        // real work -- sending, writing, charging -- and only then discover the
        // call was malformed. Validation after a side effect is not validation.
        $propsCheck = WorkflowProps::resolve($graph->inputs, $options->props);
        if ($propsCheck['ok'] === false) {
            $emit(RunEvent::runError($propsCheck['error']));

            return new RunResult(false, $outputs, $propsCheck['error'], $events);
        }
        $props = $propsCheck['props'];
        $declaresProps = $graph->inputs !== [];

        $incomingByNode = $this->indexIncoming($graph->edges);
        // Built ONCE. Used only to explain an undelivered edge, but building it
        // per node would make a diagnostic quietly O(n^2) on the hot path.
        $nodesById = [];
        foreach ($graph->nodes as $graphNode) {
            $nodesById[$graphNode->id] = $graphNode;
        }
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
                $this->publish($node, $resumeOutputs[$node->id], $outputs, $portValues, $completed, $emit, resumed: true, kinds: $executors->kinds());

                continue;
            }

            $incoming = $incomingByNode[$node->id] ?? [];

            // An ENTRY POINT that this run did not start from is inactive.
            //
            // A node with no inbound edges is unconditionally ready — that IS
            // the readiness rule — so a graph with two triggers ran both
            // branches on every run, whichever trigger actually fired. Naming
            // the live entry points makes the rest inactive here, and the
            // "at least one active inbound edge" test below then skips
            // everything reachable only from them, with no new routing logic.
            //
            // Deliberately gates ONLY nodes with no incoming edges: a node
            // further down the graph is not an entry point, and its readiness is
            // still decided by its edges. Pinned by `flow/entry-points`.
            if ($incoming === [] && $options->entryNodes !== null
                && ! in_array($node->id, $options->entryNodes, true)) {
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::IDLE, 'skipped'));

                continue;
            }

            // AN EDGE THAT DELIVERS NOTHING MUST SAY SO.
            //
            // Checked HERE, before the activity gate, because the two outcomes
            // are both silent and only one reaches `collectInputs`. If the bad
            // edge is a node's only inbound one the node is SKIPPED and never
            // collects inputs at all; if the node has another live edge it RUNS
            // with that port simply missing -- and then the downstream template
            // is completely correct and renders empty, because the payload
            // never arrived to have a field in it. A consumer misdiagnosed two
            // filed issues off the back of the second shape.
            //
            // Keyed on the source having COMPLETED, which is what separates the
            // two reasons a key can be absent. A branch that was not taken is
            // ordinary and must never warn; a source that finished and does not
            // publish this port is a misconfiguration that will never work on
            // any run. A warning that fires on ordinary branching is noise, and
            // noise is how a real warning stops being read.
            foreach ($incoming as $edge) {
                if (! array_key_exists($this->portKey($edge->source, $edge->sourceHandle), $portValues)
                    && ($completed[$edge->source] ?? false)
                    && ! in_array(
                        $edge->sourceHandle ?? 'out',
                        $this->possiblePortIds($nodesById[$edge->source] ?? null, $executors->kinds()),
                        true,
                    )) {
                    $emit(RunEvent::log(
                        'warn',
                        $this->undeliveredEdgeMessage($edge, $node, $portValues, $executors->kinds(), $nodesById),
                        $node->id,
                        ['edge' => $edge->id, 'source' => $edge->source, 'sourceHandle' => $edge->sourceHandle ?? 'out'],
                    ));
                }
            }

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

            // Note nodes are annotations — never executed. Matched across every
            // id the kind answers to: a graph saved with the canonical
            // `@particle-academy/note` must stay an annotation, not become an
            // unrunnable node.
            if ($node->type !== null && KindId::matches($node->type, 'note')) {
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::IDLE, 'annotation'));

                continue;
            }

            $emit(RunEvent::nodeStatus($node->id, NodeStatus::RUNNING));

            $inputs = $this->collectInputs($node, $incoming, $portValues, $initialInputs, $props, $declaresProps);
            $exec = $executors->resolveFor($node);
            if ($exec === null) {
                $msg = "No executor registered for kind={$node->type}";
                $errors[] = $msg;
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::ERROR, $msg));
                $emit(RunEvent::log('error', $msg, $node->id));

                break;
            }

            try {
                self::announce($emit, $node, 'start');
                $ctx = new ExecutionContext($node, $inputs, Closure::fromCallable($emit), $options->depth, $options->run, $executors);
                $result = $exec($ctx);
                $this->publish($node, $result, $outputs, $portValues, $completed, $emit, kinds: $executors->kinds());
                // Success path only, and deliberately so: a `stoppingMsg` of
                // "Analysis complete" emitted after a throw tells a human the
                // opposite of what happened, in the part of the UI they trust
                // most. Failures report through node-status and log.
                self::announce($emit, $node, 'end');
            } catch (RunAborted $e) {
                // CONTROL FLOW, NOT A FAILURE — never decorate this message.
                // `abort()` carries the reason verbatim, and `pauseForHuman()`
                // aborts with a `Pause::encode()` payload that the durable layer
                // decodes straight back out of the message. Prefixing it turns a
                // pause into an unrecognised error, and the run that should be
                // waiting on a person is simply dead instead.
                $msg = $e->getMessage();
                $errors[] = $msg;
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::ERROR, $msg));
                $emit(RunEvent::log('error', $msg, $node->id));

                break;
            } catch (Throwable $e) {
                // A genuine executor failure: attribute it to the node that was
                // running. The emitted events already carried $node->id, but
                // RunResult->error and anything catching on the durable path saw
                // the message alone -- so a good message like "raise max_tokens"
                // arrived without saying WHICH node's, and the author bisected a
                // composed Op to find out. Wrapping here covers every executor,
                // including ones that know nothing about this exception.
                $failure = NodeExecutionException::at($node->id, $node->type, $node->label, $e);
                $msg = $failure->getMessage();
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
        ?NodeKindRegistry $kinds = null,
    ): void {
        $outputs[$node->id] = $result;

        $activated = $this->activatedPorts($node, $result, $kinds);
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
     * Only *active* incoming edges contribute — the contract the TS engine
     * documents ("collectInputs() only reads from the active ones") as part of
     * the merge-after-decision fix (#1). An edge whose source port never
     * produced a value (a dead branch) is skipped, so it cannot clobber a live
     * value arriving on the same port.
     *
     * This used to be a REAL divergence: TS assigned unconditionally, so a
     * trailing dead edge overwrote a live one with `undefined` whenever two
     * branches rejoined on the same handle. PHP implemented the documented
     * contract, TS implemented the code — and the two disagreed silently, since
     * both runtimes still reported success. **TS was fixed to match in
     * fancy-flow 0.27.1**, so the runtimes now agree; the fixture
     * `23-merge-same-handle` pins the behaviour on both sides.
     *
     * @param list<FlowEdge>                    $incoming
     * @param array<string,mixed>               $portValues
     * @param array<string,array<string,mixed>> $initial
     * @return array<string,mixed>
     */
    private function collectInputs(FlowNode $node, array $incoming, array $portValues, array $initial, array $props = [], bool $declaresProps = false): array
    {
        $inputs = $initial[$node->id] ?? [];

        // ENTRY POINTS are seeded with the props by their bare names, which is
        // what lets an existing graph keep working unchanged: a trigger reading
        // `{{ topic }}` was fed by `initialInputs[triggerId]['topic']`, and a
        // caller moving to props passes `['topic' => ...]` to see exactly the
        // same thing. Only entry points -- a node mid-graph reading a bare
        // `topic` would be shadowing whatever its upstream edge is called.
        //
        // Never clobbers: a value the host already seeded is the host's.
        if ($incoming === []) {
            foreach ($props as $name => $value) {
                if (! array_key_exists($name, $inputs)) {
                    $inputs[$name] = $value;
                }
            }
        }
        foreach ($incoming as $edge) {
            $key = $this->portKey($edge->source, $edge->sourceHandle);

            if (array_key_exists($key, $portValues)) {
                $inputs[$edge->targetHandle ?? 'in'] = $portValues[$key];

                // ALSO addressable by the SOURCE NODE'S ID when the edge named
                // no handle. Authors write `{{ n2.text }}` first -- it is how
                // every graph tool addresses nodes -- and that resolved to
                // nothing while NOTHING FAILED, because an unresolvable path
                // yields ''. Silent wrong output, on a green run (#8).
                // Only for handle-less edges, and never clobbering a key that
                // is already present.
                if ($edge->targetHandle === null && ! array_key_exists($edge->source, $inputs)) {
                    $inputs[$edge->source] = $portValues[$key];
                }
            }
        }

        // EVERY node gets `$props`, entry point or not -- the half that makes
        // props usable at depth. Seeding entry points alone would mean a node
        // six hops downstream had the value threaded through every edge in
        // between, and every hop is somewhere it can be dropped.
        //
        // It costs nothing to resolve: `$props` is an ORDINARY KEY in the
        // inputs array and Expr already walks dot-paths against it, so
        // `{{ $props.topic }}` works with no change to any resolver, in any of
        // the three runtimes. Changing the resolver would have meant three
        // divergent implementations of one rule.
        //
        // ONLY when the workflow DECLARES inputs, and that was a correction.
        // An earlier draft wrote it unconditionally, justified as "so
        // `{{ $props.x }}` resolves to null rather than throwing" -- which is
        // not true: Expr yields null for any unresolvable path, so on a graph
        // declaring nothing the key changes no behaviour. What it DOES do is
        // add a key to every executor's inputs on every graph forever, and the
        // golden parity fixtures caught it instantly -- twelve of them gained a
        // `'$props' => []` line.
        //
        // Keyed on the DECLARATION, not on whether a value arrived: a workflow
        // whose inputs are all optional and all omitted still declared a
        // contract, so `$props` is present and empty.
        if ($declaresProps) {
            $inputs['$props'] = $props;
        }

        return $inputs;
    }

    /**
     * Decide which output ports an executor's result activates, and the value
     * carried. Faithful to the TS `activatedPorts`.
     *
     * @return array{ports:list<string>,value:mixed}
     */
    private function activatedPorts(FlowNode $node, mixed $result, ?NodeKindRegistry $kinds = null): array
    {
        if (is_array($result)) {
            if (isset($result['__port']) && is_string($result['__port'])) {
                return ['ports' => [$result['__port']], 'value' => $result['value'] ?? null];
            }
            if (isset($result['branch']) && is_string($result['branch'])) {
                // `array_key_exists`, NOT `??`. The two are different questions
                // and only one of them is the one being asked:
                //
                //   no `value` key at all  -> the whole result IS the payload,
                //                             which is what the fallback is for
                //   `value` present, null  -> the payload is null, pass null on
                //
                // `?? $result` cannot tell them apart, so a branch whose payload
                // was null leaked the WRAPPER downstream — every following node
                // received `['branch' => 'x', 'value' => null]`, two fields no
                // kind declares, while the fields it does declare were absent.
                //
                // The reachable path is the one that matters: `input('in', …)`
                // is null exactly when `in` is bound to an explicit null, which
                // is what an upstream `transform` produces when its dot-path
                // does not resolve. So a run that had already quietly resolved
                // to nothing then started emitting an undeclared shape too.
                //
                // `Port::only` never had this: its `?? null` yields null. Two
                // sugars documented as equivalent, differing on the one input
                // where it counts.
                return [
                    'ports' => [$result['branch']],
                    'value' => array_key_exists('value', $result) ? $result['value'] : $result,
                ];
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
            $kindPorts = ($kinds ?? NodeKindRegistry::default())->get($kindName)?->outputs;
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
     */
    private function undeliveredEdgeMessage(
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
            if (str_starts_with($key, $prefix)) {
                $available[] = substr($key, strlen($prefix));
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
     * Resolved the same way `activatedPorts` resolves them, deliberately: node
     * `outputs` first, then the kind's, then `out`. Two copies of that
     * precedence would agree until someone changed one.
     *
     * @return list<string>
     */
    private function possiblePortIds(?FlowNode $node, ?NodeKindRegistry $kinds): array
    {
        if ($node === null) {
            return ['out'];
        }

        $declared = $node->outputs;

        if ($declared === null) {
            $kindName = $node->kind();

            // An UNREGISTERED kind is not ambiguous, which is worth stating
            // because it looks as though it should be. `activatedPorts` falls
            // back to exactly `['out']` for a kind it cannot resolve -- so an
            // unknown kind deterministically publishes `out` and nothing else,
            // and naming any other handle on it really is impossible.
            //
            // An earlier attempt at this treated "kind not found" as "ports
            // unknown" and went silent. That was a mis-diagnosis of a false
            // positive whose real cause was one layer up: the Laravel provider
            // was not giving `ExecutorRegistry` the host's registry, so KNOWN
            // kinds were arriving here as unknown. Fixing the lookup fixed the
            // warning; weakening the warning only hid it.
            $kindPorts = ($kindName !== null && $kinds !== null) ? $kinds->get($kindName)?->outputs : null;
            if ($kindPorts !== null && $kindPorts !== []) {
                $declared = $kindPorts;
            }
        }

        if ($declared === null) {
            return ['out'];
        }

        return array_values(array_map(static fn (PortDescriptor $p) => $p->id, $declared));
    }

    private function portKey(string $nodeId, ?string $portId): string
    {
        return $nodeId.':'.($portId ?? 'out');
    }

    private function elapsedMs(int|float $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }

    /**
     * Emit a node's own status message for one phase, if it declared one.
     *
     * Opt-in by absence: a node with no `startingMsg` / `stoppingMsg` says
     * nothing, because most nodes in a graph are plumbing and narrating all of
     * them buries the steps a person actually follows.
     *
     * A message must be non-empty after trimming. A blank field is the shape a
     * cleared editor input takes, and a blank line in a progress feed cannot be
     * told apart from a real message that happens to render as nothing.
     *
     * @param 'start'|'end' $phase
     */
    private static function announce(callable $emit, FlowNode $node, string $phase): void
    {
        $raw = $phase === 'start' ? $node->startingMsg : $node->stoppingMsg;
        if ($raw === null) {
            return;
        }

        $message = trim($raw);
        if ($message === '') {
            return;
        }

        $emit(RunEvent::nodeMessage($node->id, $phase, $message));
    }

}
