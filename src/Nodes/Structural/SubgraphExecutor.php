<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Structural;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
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
        $executors = Builtin::executors($this->deps);

        $result = (new FlowRunner())->run(
            $import->graph,
            $executors,
            options: new RunOptions(
                initialInputs: $this->seedEntryNodes($import->graph, $ctx->inputs),
                depth: $ctx->depth + 1,
            ),
        );

        return $result->outputs;
    }
}
