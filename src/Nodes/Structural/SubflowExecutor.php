<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Structural;

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
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

        $child = $resolver->resolve($ref);
        if ($child === null) {
            $ctx->abort("subflow could not resolve workflow \"{$ref}\"");
        }

        $mode = self::mode($config);
        $streaming = $mode !== 'output';

        // Surface the child's progress on the PARENT's feed as log lines against
        // THIS node. Re-emitting the child's raw events would collide with the
        // parent's own node ids — a child's node-status for its `output` node is
        // not a status for anything in the parent graph.
        $forward = $streaming
            ? static function (RunEvent $event) use ($ctx, $ref): void {
                $ctx->emit(RunEvent::log('info', "[{$ref}] ".self::describe($event), $ctx->node->id));
            }
            : null;

        $result = (new FlowRunner())->run(
            $child,
            $this->executors ?? Builtin::executors($this->deps),
            $forward,
            new RunOptions(
                initialInputs: $this->childInputs($config, $child, $ctx->inputs),
                depth: $ctx->depth + 1,
            ),
        );

        if (! $result->ok) {
            $ctx->abort(sprintf('subflow "%s" failed: %s', $ref, $result->error ?? 'unknown error'));
        }

        // `stream` alone still emits a final value on `stream` so downstream
        // nodes have something to run on; `both` publishes on every port.
        return match ($mode) {
            'stream' => Port::only('stream', $result->outputs),
            'both' => $result->outputs,
            default => Port::only('out', $result->outputs),
        };
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
