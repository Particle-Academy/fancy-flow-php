<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

use Closure;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/**
 * Everything an executor gets when it runs — the PHP twin of the TS executor
 * `ctx { node, inputs, abort, emit }`.
 *
 *   - `$node`   the node being executed (id, kind, config, ports).
 *   - `$inputs` values arriving on each input port, keyed by port id
 *               (default port is `in`), merged with any seeded initial inputs.
 *   - `abort()` stops the whole run (throws {@see RunAborted}).
 *   - `emit()`  streams a {@see RunEvent} to the run's event sink.
 */
final class ExecutionContext
{
    /**
     * @param array<string,mixed>     $inputs
     * @param Closure(RunEvent):void  $emit
     * @param int                     $depth how deep this run is nested; `subflow`
     *        reads it to enforce a depth limit and passes depth + 1 to its child.
     * @param RunIdentity|null        $run   who is running, and which attempt of which step
     *        this is. `$ctx->run->stepKey($ctx->node->id)` is the idempotency key for a node
     *        that writes to somebody else's system — stable across retries of this step,
     *        distinct for every other execution of the same node. NULL when the host supplied
     *        no identity, and that is a real answer: a write with no key must decline or
     *        accept one attempt, never invent a key.
     */
    public function __construct(
        public readonly FlowNode $node,
        public readonly array $inputs,
        private readonly Closure $emit,
        public readonly int $depth = 0,
        public readonly ?RunIdentity $run = null,
        /**
         * The registry THIS run is executing against.
         *
         * Handed down so an executor that starts a NESTED run gives the child
         * the same executors as the parent. `SubflowExecutor` previously fell
         * back to `Builtin::executors()` — the BARE builtins — because the
         * composed registry does not exist yet when `Builtin::executors()`
         * constructs it, and nothing could be passed at that point. The child
         * therefore lost every host executor, the `agent` binding and the
         * container resolver: a host kind resolved at top level and vanished
         * one level down, and a host that had REPLACED a builtin got the
         * package's version inside the child (issue #7).
         *
         * Inheriting through the context rather than through construction is
         * what makes it unforgettable — any future nesting executor gets it
         * without opting in.
         */
        public readonly ?ExecutorRegistry $executors = null,
        /**
         * Resume outputs belonging to work NESTED INSIDE this node, keyed by the
         * nested node's own id with this node's prefix already stripped.
         *
         * Empty for every node that does not enclose a graph, which is almost
         * all of them. A `subflow` hands this straight to the child runner as
         * its `resumeOutputs`, and that is what stops a child node which already
         * committed from executing a second time when the PARENT resumes — the
         * defect measured at 2 in `tests/Durable/SubflowChildReplayTest.php`.
         *
         * The slicing rule lives in {@see \FancyFlow\Engine\FlowRunner}, once,
         * so any enclosing executor gets it rather than each reimplementing it.
         *
         * @var array<string,mixed>
         */
        public readonly array $resumeOutputs = [],
        /** The graph this node belongs to. Structural executors use it to derive nested lanes. */
        public readonly ?FlowGraph $graph = null,
        /** Qualified durable address; the bare node id at the top level. */
        public readonly ?string $address = null,
        /** Whether this execution is the only possible target for a legacy bare answer. */
        public readonly bool $allowLegacyBareAddress = true,
        /**
         * Human answers recorded for this run, keyed by node address.
         *
         * Read it with {@see humanAnswer()} rather than directly.
         *
         * @var array<string,mixed>
         */
        public readonly array $humanAnswers = [],
    ) {}

    public function nodeAddress(): string
    {
        return $this->address ?? $this->node->id;
    }

    /**
     * The human answer recorded for THIS node, or null if none has arrived.
     *
     * The counterpart to {@see pauseForHuman()}, which until 0.60 had none: a
     * pausing executor could park and could never learn its answer had come
     * back. Delivery was executor SUBSTITUTION keyed by kind, so it reached the
     * two builtin human kinds and nothing else — a host-registered gate inside
     * a subflow parked, accepted its answer, and re-parked forever
     * (fancy-flow-php#22). Any executor can now do:
     *
     * **Returns the RECORD, not the payload** — `['values' => …]` for a form,
     * `['approved' => bool]` for an approval. That distinction is load-bearing
     * and this example used to get it wrong:
     *
     * ```php
     * // WRONG, and it fails SILENTLY. The legacy `values` port handed the
     * // values themselves, so a host migrating to this found `$values['x']`
     * // suddenly absent, read that as a rejection, took the other branch, and
     * // the run went GREEN having skipped the step after the gate.
     * if (($answer = $ctx->humanAnswer()) !== null) {
     *     return $answer;
     * }
     * ```
     *
     * Use the payload accessors instead, which is what a migrating host wants:
     *
     * ```php
     * if (($values = $ctx->humanValues()) !== null) {
     *     return $values;                       // same shape the `values` port gave
     * }
     *
     * $ctx->pauseForHuman('input', $detail);
     * ```
     *
     * ```php
     * if (($approved = $ctx->humanApproved()) !== null) {
     *     return Port::branch($approved ? 'approved' : 'rejected', null);
     * }
     *
     * $ctx->pauseForHuman('approval', $detail);
     * ```
     *
     * Keyed by ADDRESS, so it works unchanged at depth: a gate inside `call`
     * reads `call/gate`, and one in the second iteration of `each` reads
     * `each/1/gate`. That is the same address the checkpoint and the pause
     * already use, so nothing new has to agree about naming.
     *
     * Falls back to the bare node id ONLY where the context proves there is a
     * single possible occurrence ({@see allowsLegacyNestedAddress()}), which is
     * what keeps a pre-qualified-era answer from satisfying two siblings.
     *
     * Returning null and "answered with null" are deliberately NOT
     * distinguished here: an executor that needs that distinction should check
     * {@see hasHumanAnswer()}.
     */
    public function humanAnswer(): mixed
    {
        return $this->humanAnswers[$this->answerKey()] ?? null;
    }

    /**
     * The form payload of this node's answer — the shape the legacy `values`
     * input port carried, so a host migrating off `$ctx->inputs['values']`
     * swaps one for the other with no change in meaning.
     *
     * Null when nothing has been answered AND when the recorded answer is an
     * approval rather than a form.
     */
    public function humanValues(): mixed
    {
        $answer = $this->humanAnswer();

        return is_array($answer) ? ($answer['values'] ?? null) : null;
    }

    /**
     * The decision of this node's answer, or null if it has not been answered
     * (or was answered with a form rather than an approval).
     *
     * Null-vs-false matters here: `false` is a REJECTION, `null` is silence.
     * Branch on `!== null`, never on truthiness, or a rejection reads as
     * un-answered and the node pauses again forever.
     */
    public function humanApproved(): ?bool
    {
        $answer = $this->humanAnswer();

        if (! is_array($answer) || ! array_key_exists('approved', $answer)) {
            return null;
        }

        return (bool) $answer['approved'];
    }

    /** Whether an answer has been recorded, even one whose value is null. */
    public function hasHumanAnswer(): bool
    {
        return array_key_exists($this->answerKey(), $this->humanAnswers);
    }

    /** The key this node's answer is stored under, honouring the legacy fallback. */
    private function answerKey(): string
    {
        $address = $this->nodeAddress();

        if (array_key_exists($address, $this->humanAnswers)) {
            return $address;
        }

        $bare = $this->node->id;

        if ($address !== $bare
            && $this->allowLegacyBareAddress
            && array_key_exists($bare, $this->humanAnswers)) {
            return $bare;
        }

        return $address;
    }

    /**
     * Whether a child graph is the sole address-producing occurrence here.
     *
     * A pre-qualified answer such as `gate` is safe to inherit only when this
     * graph has one structural expansion point. Two sibling subflows/loops can
     * both contain `gate`; accepting the bare key in either would let one old
     * answer satisfy both occurrences. Conservatively refusing the fallback
     * for graphs with multiple expansion points preserves safety even when the
     * children's schemas are resolved dynamically.
     */
    public function allowsLegacyNestedAddress(): bool
    {
        if (! $this->allowLegacyBareAddress || $this->graph === null) {
            return false;
        }

        $expansionPoints = 0;
        foreach ($this->graph->nodes as $node) {
            $kind = $node->kind();
            if ($kind === null || (! KindId::matches($kind, 'for_each')
                && ! KindId::matches($kind, 'subflow')
                && ! KindId::matches($kind, 'subgraph'))) {
                continue;
            }
            $expansionPoints++;
            if ($expansionPoints > 1) {
                return false;
            }
        }

        return $expansionPoints === 1;
    }

    /** Stop the run. Throws {@see RunAborted}; the runner records the reason. */
    public function abort(?string $reason = null): never
    {
        throw new RunAborted($reason ?? 'aborted');
    }

    /**
     * Halt the run to wait for a person.
     *
     * Node authors should reach for this rather than hand-encoding a reason, so
     * the format stays ours to change:
     *
     *     $values = $ctx->inputs['values'] ?? null;
     *     if ($values === null) {
     *         $ctx->pauseForHuman('input', ['fields' => $fields]);
     *     }
     *
     * Note the strict null check — an empty submission (`[]`) is a real answer
     * and must resume. A truthiness test pauses forever on an empty form.
     */
    public function pauseForHuman(string $awaiting, mixed $detail = null): never
    {
        $this->abort(Pause::encode(new PauseSignal($this->nodeAddress(), $awaiting, $detail)));
    }

    /** Stream a status update or partial output to the run feed. */
    public function emit(RunEvent $event): void
    {
        ($this->emit)($event);
    }

    /** Read one input port's value (default port `in`). */
    public function input(string $port = 'in', mixed $default = null): mixed
    {
        // `array_key_exists`, NOT `??`. A port BOUND to null is not an ABSENT
        // port, and only the absent one may fall back.
        //
        // Eleven executors call `$ctx->input('in', $ctx->inputs)`, whose default
        // is the whole inputs map. With `??`, a port holding an explicit null
        // did not yield null — it yielded every input the node had. And unlike
        // the wrapper leak this sits underneath, the substitute is PLAUSIBLE: an
        // inputs map looks exactly like real data, so a downstream node reads
        // fields that came from the wrong place and nothing looks wrong.
        //
        // The fallback itself is right and stays. A trigger has no `in` edge,
        // and "the `in` port, or everything if there is no `in` port" is what
        // lets an entry node read its seeded payload.
        //
        // Third layer of one collapse — this, `activatedPorts`, and the
        // regression test written for `activatedPorts`, which asserted with
        // `?? '__absent__'` and could not see the null it tested for. The rule:
        // **`??` is safe only where null is not a legal value.**
        return array_key_exists($port, $this->inputs) ? $this->inputs[$port] : $default;
    }

    /** The node's resolved config array. */
    public function config(): array
    {
        return $this->node->config;
    }

    /** Read one config key. */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->node->config[$key] ?? $default;
    }
}
