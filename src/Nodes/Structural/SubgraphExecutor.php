<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Structural;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\Exceptions\UnreadableWorkflow;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\PartialResult;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;

/**
 * `subgraph` — runs a nested workflow. The nested WorkflowSchema lives in the
 * node's `graph` config; its entry nodes are seeded with this node's inputs, and
 * the nested run's outputs are returned. This is the `runFlow` recursion that
 * lets agents compose sub-workflows. With no nested graph, the input passes
 * through.
 */
final class SubgraphExecutor implements NodeExecutor
{
    use SeedsEntryNodes;

    public function __construct(private readonly ExecutorDeps $deps) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $graph = $ctx->option('graph');
        if (! is_array($graph)) {
            return $ctx->input('in', $ctx->inputs);
        }

        $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
        $import = Workflow::import($graph, lenient: true, registry: $registry);
        // A nested graph that cannot be read fails THIS node. Running the empty
        // graph a refused import returns would pass the node with nothing done.
        if ($import->refused()) {
            throw UnreadableWorkflow::from($import);
        }
        $executors = Builtin::executors($this->deps);

        $checkpointPrefix = RunIdentity::escapeSegment($ctx->node->id).'/';
        $result = (new FlowRunner())->run(
            $import->graph,
            $executors,
            onEvent: static function (RunEvent $event) use ($ctx, $checkpointPrefix): void {
                if ($event->type === RunEvent::NODE_CHECKPOINT && $event->nodeId !== null) {
                    $ctx->emit(RunEvent::nodeCheckpoint($checkpointPrefix.$event->nodeId, $event->value));
                }
            },
            options: new RunOptions(
                initialInputs: $this->seedEntryNodes($import->graph, $ctx->inputs),
                depth: $ctx->depth + 1,
                run: $ctx->run?->descend($ctx->node->id),
                resumeOutputs: $ctx->resumeOutputs,
                addressPrefix: $ctx->nodeAddress().'/',
                allowLegacyBareAddress: $ctx->allowsLegacyNestedAddress(),
            ),
        );

        foreach ($result->outputs as $childId => $childResult) {
            $ctx->emit(RunEvent::nodeCheckpoint(
                $checkpointPrefix.RunIdentity::escapeSegment((string) $childId),
                $childResult,
            ));
        }

        if (! $result->ok && ! $result->isPartial()) {
            $reason = (string) ($result->error ?? 'unknown error');
            if (Pause::decode($reason) !== null) {
                $ctx->abort($reason);
            }
            $ctx->abort('subgraph failed: '.$reason);
        }

        return $result->isPartial()
            ? new PartialResult($result->outputs, 'subgraph completed partially: '.(string) $result->error)
            : $result->outputs;
    }
}
