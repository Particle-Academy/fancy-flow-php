<?php

declare(strict_types=1);

namespace FancyFlow\Engine;

use Closure;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Exceptions\NodeExecutionException;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Registry\PortResolution;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\NodeStatus;
use FancyFlow\Runtime\PartialResult;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunIdentity;
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
 *   1b. `['__ports' => ['x','y'], 'value' => …]` → exactly those emit, sharing
 *       the payload; `['__ports' => ['x' => …, 'y' => …]]` gives each its own.
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
        /** @var list<string> $partialErrors */
        $partialErrors = [];
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

        if (($msg = $this->checkpointAddressCollision($graph)) !== null) {
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
            $resumeKey = $options->addressPrefix === ''
                ? $node->id
                : RunIdentity::escapeSegment($node->id);
            if (array_key_exists($resumeKey, $resumeOutputs)) {
                $this->publish($node, $resumeOutputs[$resumeKey], $outputs, $portValues, $completed, $emit, resumed: true, kinds: $executors->kinds());

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
            //
            // The rule is {@see UndeliveredEdges::warnings()}, shared with the
            // `per_node` driver, which asks it again when it records a skip: a
            // skipped node never gets a job of its own, so that is the only
            // moment a durable run can say this about it.
            foreach (UndeliveredEdges::warnings($node, $incoming, $portValues, $completed, $nodesById, $executors->kinds()) as $warning) {
                $emit($warning);
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

            // Annotations and LAYOUT nodes never execute.
            //
            // This matched `note` and nothing else, so a graph containing the
            // `@particle-academy/lane` the TypeScript runtime ships -- and
            // walks straight past -- failed here with "No executor registered
            // for kind=lane". Same WorkflowSchema, different answer per
            // runtime, which is the one guarantee this package makes.
            //
            // `GraphConnectivity::mayFloat()` already knew, naming the lane as
            // "a swimlane its engine walks straight past", and `RunEvent`
            // already documented a "lane" status text nothing had ever
            // emitted. The analysis knew, the runner did not, and nothing
            // compared them.
            $skip = self::neverExecutes($node->type, $executors->kinds());

            if ($skip !== null) {
                $emit(RunEvent::nodeStatus($node->id, NodeStatus::IDLE, $skip));

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
                $ctx = new ExecutionContext(
                    $node,
                    $inputs,
                    Closure::fromCallable($emit),
                    $options->depth,
                    $options->run,
                    $executors,
                    self::nestedResumeOutputs($resumeOutputs, $node->id),
                    $graph,
                    $options->addressPrefix.RunIdentity::escapeSegment($node->id),
                    $options->allowLegacyBareAddress,
                );
                $result = $exec($ctx);
                if ($result instanceof PartialResult) {
                    $partialErrors[] = $result->error;
                    $result = $result->value;
                }
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

        $outcome = $errors !== []
            ? RunResult::FAILED
            : ($partialErrors !== [] ? RunResult::PARTIAL : RunResult::COMPLETED);
        $ok = $outcome === RunResult::COMPLETED;
        $emit(RunEvent::runEnd($ok));

        return new RunResult(
            $ok,
            $outputs,
            $errors[0] ?? $partialErrors[0] ?? null,
            $events,
            $outcome,
        );
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
            // `array_key_exists` on the per-port map, never `??`: a payload that
            // is present and null is a payload, and `?? $value` would hand that
            // port the shared value instead. Same distinction as `branch`.
            $value = isset($activated['values']) && array_key_exists($portId, $activated['values'])
                ? $activated['values'][$portId]
                : $activated['value'];

            $portValues[$this->portKey($node->id, $portId)] = $value;
            $emit(RunEvent::nodeOutput($node->id, $portId, $value));
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
    /**
     * The status text for a kind the engine walks past, or null to run it.
     *
     * Mirrors {@see GraphConnectivity::mayFloat()} on purpose -- a node that
     * may float unconnected and a node that never executes are the same set,
     * and the two answering differently is how a lane became floatable and
     * unrunnable at the same time.
     *
     * The kinds this kit SHIPS are matched by id as well as by category,
     * because a caller's registry may not have them: PHP has never declared a
     * `lane` kind, and `mayFloat`'s own comment already had to work around
     * exactly that -- "a laned graph authored in the TS editor carries `lane`
     * nodes that PHP's registry does not have".
     *
     * An UNKNOWN kind returns null -- running is the default, and a kind
     * nobody registered still needs an executor. `mayFloat` differs there, and
     * only there: it lets an unknown kind float because it cannot know what
     * the kind is, which is the honest answer to a different question.
     */
    private static function neverExecutes(?string $kindId, ?NodeKindRegistry $kinds): ?string
    {
        if ($kindId === null || $kindId === '') {
            return null;
        }

        if (KindId::matches($kindId, 'note')) {
            return 'annotation';
        }

        if (KindId::matches($kindId, 'lane') || KindId::matches($kindId, 'terminal_lane')) {
            return 'lane';
        }

        $kind = $kinds?->get($kindId);

        if ($kind === null) {
            return null;
        }

        return match ($kind->category) {
            'annotation' => 'annotation',
            'layout' => 'lane',
            default => null,
        };
    }

    private function activatedPorts(FlowNode $node, mixed $result, ?NodeKindRegistry $kinds = null): array
    {
        if (is_array($result)) {
            if (isset($result['__port']) && is_string($result['__port'])) {
                return ['ports' => [$result['__port']], 'value' => $result['value'] ?? null];
            }
            // A CHOSEN SUBSET (#18, reported by MOIC): a LIST lights those ports
            // with one payload, a MAP gives each lit port its own. Before this a
            // node could light one port or all of them, so a router that matched
            // two of five silently dropped work or woke lanes nobody asked for.
            //
            // An empty array lights NOTHING, deliberately: the same answer an
            // explicitly empty `outputs` gives below.
            if (isset($result['__ports']) && is_array($result['__ports'])) {
                $ports = $result['__ports'];

                if (array_is_list($ports)) {
                    return [
                        'ports' => array_values(array_filter($ports, is_string(...))),
                        'value' => $result['value'] ?? null,
                    ];
                }

                return [
                    'ports' => array_map(strval(...), array_keys($ports)),
                    'value' => $result['value'] ?? null,
                    'values' => $ports,
                ];
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
            // The KIND's ports, INCLUDING an empty list. Until 0.56.0 an empty
            // one was refused here, because consuming it literally publishes
            // zero ports where the historical fallback published `out` — and
            // that silently cut every chain through such a node.
            //
            // The protection is gone because the silence is gone. An edge
            // leaving a node that published nothing now raises the
            // undelivered-edge warning, so a truncated chain announces itself
            // instead of being papered over with a port the node never
            // declared. Keeping the refusal as well would mean a terminal kind
            // could never actually terminate — and the owner's ruling was
            // strict-but-loud, not lenient.
            $declared = ($kinds ?? NodeKindRegistry::default())->get($kindName)?->outputs;
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


    /**
     * The slice of `resumeOutputs` that belongs INSIDE `$nodeId`, with the
     * prefix stripped so the nested graph sees its own bare node ids.
     *
     * Addresses are `parent/child`, qualified the same way {@see RunIdentity}
     * qualifies an idempotency key — because they answer the same question:
     * *which execution of which node is this?* A bare node id cannot, since a
     * child graph legitimately contains a node named like one in the parent.
     *
     * A map with no qualified keys slices to nothing and costs one pass, which
     * is the normal case. **That is what makes this backward compatible**: a
     * flat `resumeOutputs` behaves exactly as it did before addresses existed.
     *
     * @param  array<string,mixed>  $resumeOutputs
     * @return array<string,mixed>
     */
    private static function nestedResumeOutputs(array $resumeOutputs, string $nodeId): array
    {
        $prefix = RunIdentity::escapeSegment($nodeId).'/';
        $len = strlen($prefix);
        $nested = [];

        foreach ($resumeOutputs as $address => $value) {
            if (is_string($address) && str_starts_with($address, $prefix)) {
                $nested[substr($address, $len)] = $value;
            }
        }

        return $nested;
    }

    /**
     * Reject a top-level id that could be mistaken for progress inside a
     * structural node. Top-level checkpoints retain raw ids for compatibility;
     * nested addresses encode each segment and join them with `/`. Without this
     * guard, a node literally named `each/0/write` could consume the checkpoint
     * emitted by item 0's `write` node and silently skip its own executor.
     */
    private function checkpointAddressCollision(FlowGraph $graph): ?string
    {
        $iteratingNodes = [];
        foreach ($graph->edges as $edge) {
            if (($edge->sourceHandle ?? 'out') === 'item') {
                $iteratingNodes[$edge->source] = true;
            }
        }

        foreach ($graph->nodes as $parent) {
            $kind = $parent->kind();
            $expands = $kind !== null && (
                KindId::matches($kind, 'subflow')
                || KindId::matches($kind, 'subgraph')
                || (KindId::matches($kind, 'for_each')
                    && $parent->configValue('mode') !== 'collect'
                    && isset($iteratingNodes[$parent->id]))
            );
            if (! $expands) {
                continue;
            }

            $prefix = RunIdentity::escapeSegment($parent->id).'/';
            foreach ($graph->nodes as $node) {
                if ($node !== $parent && str_starts_with($node->id, $prefix)) {
                    return sprintf(
                        'Node id "%s" aliases the checkpoint namespace of structural node "%s"; rename one before running.',
                        $node->id,
                        $parent->id,
                    );
                }
            }
        }

        return null;
    }
}
