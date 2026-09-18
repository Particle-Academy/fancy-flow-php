<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Structural;

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\PartialResult;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\PortDescriptor;

/**
 * `subflow` — run another workflow and bring its result home. The PHP port of
 * fancy-flow's `subflow.ts`.
 *
 * Core, not marketplace: it introduces no third-party dependency. It runs a
 * child graph through the very same {@see FlowRunner}, so the only thing it
 * needs from the host is WHERE workflows live — a {@see WorkflowResolver}.
 *
 * Sibling to {@see SubgraphExecutor}, which is the embedded case: `subgraph`
 * carries its child graph inline in config, `subflow` NAMES one the host
 * resolves. Both seed the child's entry nodes identically (see {@see SeedsEntryNodes}).
 *
 * Three output modes, because both halves are genuinely useful:
 *   - `output` — the child's outputs arrive on `out` when it finishes.
 *   - `stream` — the child's progress is forwarded live on the parent's feed.
 *   - `both`   — stream while running AND deliver the final outputs.
 *
 * Recursion is guarded by depth: a workflow that references itself (directly or
 * through a chain) would otherwise recurse until the stack dies, surfacing as an
 * opaque crash rather than "you built a loop".
 */
final class SubflowExecutor implements NodeExecutor
{
    use SeedsEntryNodes;

    public const DEFAULT_MAX_DEPTH = 8;

    public function __construct(
        private readonly ExecutorDeps $deps = new ExecutorDeps(),
        private readonly ?WorkflowResolver $resolver = null,
        private readonly ?ExecutorRegistry $executors = null,
    ) {}

    /**
     * The mode, defaulting to `output` for anything unrecognised.
     *
     * @param array<string,mixed> $config
     */
    public static function mode(array $config): string
    {
        $mode = $config['mode'] ?? null;

        return $mode === 'stream' || $mode === 'both' ? $mode : 'output';
    }

    /**
     * Ports follow the mode — `stream` only exists when something streams.
     *
     * The twin of the TS kind's `outputs: (config) => subflowPorts(config)`;
     * exposed as a function because PHP {@see \FancyFlow\Registry\NodeKind}
     * declares static ports.
     *
     * @param array<string,mixed> $config
     * @return list<PortDescriptor>
     */
    public static function ports(array $config): array
    {
        $ports = [new PortDescriptor('out', 'result')];
        if (self::mode($config) !== 'output') {
            array_unshift($ports, new PortDescriptor('stream', 'stream'));
        }

        return $ports;
    }

    public function execute(ExecutionContext $ctx): mixed
    {
        $config = $ctx->config();
        $ref = trim((string) ($config['workflow'] ?? ''));
        if ($ref === '') {
            $ctx->abort('subflow has no workflow reference configured');
        }

        $resolver = $this->resolver ?? Capabilities::workflowResolver();
        if ($resolver === null) {
            $ctx->abort(
                'subflow: no workflow resolver registered. Register one with '
                .'FancyFlow\\Capabilities\\Capabilities::setWorkflowResolver() (or bind '
                .'FancyFlow\\Capabilities\\WorkflowResolver in the container) so subflow can find '
                .'the workflow it references.',
            );
        }

        $maxDepth = $this->maxDepth($config);
        if ($ctx->depth + 1 > $maxDepth) {
            // Name the cause. "Maximum function nesting level reached" tells an
            // author nothing about the workflow they wired into itself.
            $ctx->abort(sprintf(
                'subflow depth limit reached (%d) at "%s" — a workflow is referencing itself, '
                .'directly or through a chain.',
                $maxDepth,
                $ref,
            ));
        }

        // An optional pin. A workflow another workflow depends on is an
        // interface: without a pin, someone edits the child and this flow
        // silently runs different logic while still reporting success.
        $pin = $config['version'] ?? null;
        if ($pin !== null && $pin !== '' && ! is_int($pin) && ! ctype_digit((string) $pin)) {
            $ctx->abort(sprintf('subflow "%s" has a non-integer version pin (%s).', $ref, (string) $pin));
        }
        $version = ($pin === null || $pin === '') ? null : (int) $pin;

        $child = $resolver->resolve($ref, $version);

        if ($child instanceof WorkflowResolutionFailure) {
            // A mismatch names BOTH versions. Reporting it as "not found" would
            // send an author looking for a workflow that is sitting right there.
            $ctx->abort($child->message ?? ($child->isVersionMismatch()
                ? sprintf(
                    'subflow "%s" is pinned to version %s, but the host has %s.',
                    $ref,
                    (string) $version,
                    $child->available === null ? 'a different version' : (string) $child->available,
                )
                : "subflow could not resolve workflow \"{$ref}\""));
        }

        if ($child === null) {
            $ctx->abort("subflow could not resolve workflow \"{$ref}\"");
        }

        $mode = self::mode($config);
        $streaming = $mode !== 'output';

        // Surface the child's progress on the PARENT's feed as log lines against
        // THIS node. Re-emitting the child's raw events would collide with the
        // parent's own node ids — a child's node-status for its `output` node is
        // not a status for anything in the parent graph.
        // A child's raw events are NEVER re-emitted on the parent's feed: a
        // child's status for its own `output` node is not a status for anything
        // in the parent graph, and a consumer keying on node id would collide.
        // Only the tagged human-readable mirror goes out, and only when asked.
        $parentId = $ctx->node->id;
        $checkpointPrefix = RunIdentity::escapeSegment($parentId).'/';
        $forward = static function (RunEvent $event) use ($ctx, $ref, $parentId, $streaming, $checkpointPrefix): void {
            if ($event->type === RunEvent::NODE_CHECKPOINT && $event->nodeId !== null) {
                $ctx->emit(RunEvent::nodeCheckpoint($checkpointPrefix.$event->nodeId, $event->value));
            }
            if ($streaming) {
                $ctx->emit(RunEvent::log('info', "[{$ref}] ".self::describe($event), $parentId));
            }
        };

        $result = (new FlowRunner())->run(
            $child,
            // Inherited first: the registry the PARENT is running with, so a
            // host kind resolves at every depth. An explicitly injected
            // registry still wins for a caller that constructed this executor
            // deliberately; the bare builtins remain only as the last resort.
            //
            // Inherited WITHOUT its node-id bindings: those name nodes of the
            // parent graph, and a child node that shares an id is not that node.
            // See ExecutorRegistry::withoutNodeBindings().
            $ctx->executors?->withoutNodeBindings() ?? $this->executors ?? Builtin::executors($this->deps),
            $forward,
            new RunOptions(
                initialInputs: $this->childInputs($config, $child, $ctx->inputs),
                depth: $ctx->depth + 1,
                // Push THIS node onto the identity path, so a node inside the
                // child graph cannot share an idempotency key with a same-named
                // node in the parent — or with the same child graph invoked
                // from a different parent node. Attempt and the first-attempt
                // clock ride down unchanged: the child's work happens inside
                // this node's attempt.
                run: $ctx->run?->descend($ctx->node->id),
                // What this node's own checkpoints said about work INSIDE it.
                // FlowRunner sliced these off the parent's map and stripped this
                // node's prefix, so they arrive keyed by the CHILD's bare node
                // ids -- exactly the shape a child runner resumes from. Empty on
                // a first attempt; on a resume after a gate it is what stops the
                // child re-running work it already committed.
                resumeOutputs: $ctx->resumeOutputs,
                addressPrefix: $ctx->nodeAddress().'/',
                allowLegacyBareAddress: $ctx->allowsLegacyNestedAddress(),
            ),
        );

        // THE CHECKPOINT, and it must happen before the pause is propagated.
        //
        // Every child node that COMPLETED is re-emitted at a QUALIFIED address
        // -- `parent/child` -- carrying that node's result. The durable layer
        // writes a claim row per address, so work done at depth is checkpointed
        // at depth. Without it the whole child collapses into the ONE claim row
        // belonging to this node, and a child node that ran before a gate runs
        // AGAIN when the parent resumes: measured at 2 in
        // `tests/Durable/SubflowChildReplayTest.php`, and fancy-flow-php#22.
        //
        // `emit` is the only channel the pure core has to the durable layer, and
        // that is the point -- the engine never learns there is a database, and
        // the durable layer learns about depth without reaching in here.
        //
        // `$result->outputs` is populated even when `$result->ok` is false, so a
        // child parked on a human gate still reports everything it finished
        // first. That is exactly the case this exists for.
        foreach ($result->outputs as $childId => $childResult) {
            $ctx->emit(RunEvent::nodeCheckpoint(
                $checkpointPrefix.RunIdentity::escapeSegment((string) $childId),
                $childResult,
            ));
        }

        if (! $result->ok && ! $result->isPartial()) {
            $reason = (string) ($result->error ?? 'unknown error');

            // A PAUSE IS NOT A FAILURE, and it travels this same channel.
            //
            // Until 0.57.0 every unsuccessful child run was wrapped as
            // `subflow "x" failed: <reason>`. `Pause::decode()` is
            // prefix-anchored, so a `human_approval` or `user_input` one level
            // down produced a string that no longer decoded: the durable layer
            // read a FAILED run instead of a run parked on a person, the gate
            // became unresumable, and a retry policy counted someone's pending
            // decision as a fault.
            //
            // This is the invariant stated in the repo's own agent guide --
            // an abort's reason is VERBATIM, precisely because a human gate
            // pauses through it -- broken at the one place that wraps. The
            // Rust twin never had it and says why at the same line; PHP,
            // TypeScript and Python all did.
            if (Pause::decode($reason) !== null) {
                $ctx->abort($reason);
            }

            // A genuine failure still names the subflow. That context is worth
            // keeping; it is only the pause that must travel untouched.
            $ctx->abort(sprintf('subflow "%s" failed: %s', $ref, $reason));
        }

        // `stream` alone still emits a final value on `stream` so downstream
        // nodes have something to run on; `both` publishes on every port.
        $value = match ($mode) {
            'stream' => Port::only('stream', $result->outputs),
            'both' => $result->outputs,
            default => Port::only('out', $result->outputs),
        };

        return $result->isPartial()
            ? new PartialResult($value, sprintf(
                'subflow "%s" completed partially: %s',
                $ref,
                (string) ($result->error ?? 'child work failed'),
            ))
            : $value;
    }

    /** A child event rendered as one line of parent-visible progress. */
    private static function describe(RunEvent $event): string
    {
        return match ($event->type) {
            RunEvent::NODE_STATUS => trim("{$event->nodeId} {$event->status}"),
            RunEvent::RUN_END => 'finished ('.($event->ok ? 'ok' : 'failed').')',
            default => $event->type,
        };
    }

    /**
     * Entry-point inputs for the child run: the node's explicit mapping, or —
     * with none — the parent's inputs handed to every entry node, so the simple
     * case needs no configuration at all.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $inputs
     * @return array<string,array<string,mixed>>
     */
    private function childInputs(array $config, FlowGraph $child, array $inputs): array
    {
        $mapping = $config['inputs'] ?? null;

        return is_array($mapping) && $mapping !== [] ? $mapping : $this->seedEntryNodes($child, $inputs);
    }

    /** @param array<string,mixed> $config */
    private function maxDepth(array $config): int
    {
        $raw = $config['maxDepth'] ?? null;

        return is_int($raw) || (is_float($raw) && is_finite($raw)) ? (int) $raw : self::DEFAULT_MAX_DEPTH;
    }
}
