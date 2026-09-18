<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\PartialResult;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;

/**
 * `for_each` — runs the lane reachable from `item` once per resolved item.
 *
 * The lane is derived from the graph: nodes reachable from `item`, stopping at
 * anything also reachable from `done`. With no `item` edge (or explicit
 * `mode: collect`) the legacy data-only behaviour remains available.
 */
final class ForEachExecutor implements NodeExecutor
{
    private const int DEFAULT_MAX_ITEMS = 1000;

    private const int HARD_MAX_ITEMS = 10000;

    public function execute(ExecutionContext $ctx): mixed
    {
        $source = Expr::evaluate($ctx->option('source'), $ctx->inputs);
        $items = is_array($source) ? array_values($source) : ($source === null ? [] : [$source]);

        $lane = $ctx->graph === null ? null : $this->lane($ctx->graph, $ctx->node->id);
        if ($lane === null || $ctx->option('mode') === 'collect') {
            return ['items' => $items, 'count' => count($items)];
        }

        if ($lane['graph']->nodes === []) {
            $ctx->abort("for_each \"{$ctx->node->id}\" has an item edge but its derived lane is empty");
        }

        $maxItems = (int) $ctx->option('maxItems', self::DEFAULT_MAX_ITEMS);
        if ($maxItems < 1 || $maxItems > self::HARD_MAX_ITEMS) {
            $ctx->abort(sprintf(
                'for_each "%s" maxItems must be between 1 and %d',
                $ctx->node->id,
                self::HARD_MAX_ITEMS,
            ));
        }
        if (count($items) > $maxItems) {
            $ctx->abort(sprintf(
                'for_each "%s" resolved %d items exceeds its maxItems cap of %d',
                $ctx->node->id,
                count($items),
                $maxItems,
            ));
        }

        $results = [];
        $failures = [];
        foreach ($items as $index => $item) {
            $initialInputs = [];
            foreach ($lane['entries'] as $edge) {
                $initialInputs[$edge->target][$edge->targetHandle ?? 'in'] = $item;
            }

            $parentAddress = RunIdentity::escapeSegment($ctx->node->id).'/'.$index;
            $nested = (new FlowRunner())->run(
                $lane['graph'],
                $ctx->executors?->withoutNodeBindings() ?? throw new \LogicException('for_each requires the active executor registry'),
                onEvent: function (RunEvent $event) use ($ctx, $parentAddress): void {
                    if ($event->type === RunEvent::NODE_CHECKPOINT) {
                        $ctx->emit(RunEvent::nodeCheckpoint($parentAddress.'/'.(string) $event->nodeId, $event->value));
                    }
                },
                options: new RunOptions(
                    initialInputs: $initialInputs,
                    props: is_array($ctx->inputs['$props'] ?? null) ? $ctx->inputs['$props'] : [],
                    depth: $ctx->depth + 1,
                    run: $ctx->run?->descend($ctx->node->id, $index),
                    resumeOutputs: $this->iterationResumeOutputs($ctx->resumeOutputs, $index),
                    addressPrefix: $ctx->nodeAddress().'/'.$index.'/',
                    allowLegacyBareAddress: $ctx->allowsLegacyNestedAddress() && count($items) === 1,
                ),
            );

            foreach ($nested->outputs as $childId => $childResult) {
                $ctx->emit(RunEvent::nodeCheckpoint(
                    $parentAddress.'/'.RunIdentity::escapeSegment((string) $childId),
                    $childResult,
                ));
            }

            if (! $nested->ok) {
                $reason = (string) ($nested->error ?? 'unknown error');
                if (Pause::decode($reason) !== null) {
                    $ctx->abort($reason);
                }

                $results[] = null;
                $failures[] = [
                    'index' => $index,
                    'item' => $item,
                    'error' => $reason,
                ];

                continue;
            }

            $results[] = $nested->outputs;
        }

        $aggregate = Port::only('done', [
            'items' => $items,
            'results' => $results,
            'failures' => $failures,
            'count' => count($items),
        ]);

        return $failures === []
            ? $aggregate
            : new PartialResult(
                $aggregate,
                sprintf('for_each "%s" completed with %d failed item(s)', $ctx->node->id, count($failures)),
            );
    }

    /**
     * @return array{graph:FlowGraph,entries:list<FlowEdge>}|null
     */
    private function lane(FlowGraph $graph, string $nodeId): ?array
    {
        $itemEdges = array_values(array_filter(
            $graph->edges,
            static fn (FlowEdge $edge): bool => $edge->source === $nodeId && ($edge->sourceHandle ?? 'out') === 'item',
        ));

        if ($itemEdges === []) {
            return null;
        }

        $doneStarts = array_map(
            static fn (FlowEdge $edge): string => $edge->target,
            array_values(array_filter(
                $graph->edges,
                static fn (FlowEdge $edge): bool => $edge->source === $nodeId && ($edge->sourceHandle ?? 'out') === 'done',
            )),
        );
        $itemStarts = array_map(static fn (FlowEdge $edge): string => $edge->target, $itemEdges);

        $adjacency = [];
        foreach ($graph->edges as $edge) {
            $adjacency[$edge->source][] = $edge->target;
        }

        $done = $this->reachable($adjacency, $doneStarts);
        $body = array_diff_key($this->reachable($adjacency, $itemStarts), $done, [$nodeId => true]);

        $nodes = array_values(array_filter(
            $graph->nodes,
            static fn ($node): bool => isset($body[$node->id]),
        ));
        $edges = array_values(array_filter(
            $graph->edges,
            static fn (FlowEdge $edge): bool => isset($body[$edge->source], $body[$edge->target]),
        ));
        $entries = array_values(array_filter(
            $itemEdges,
            static fn (FlowEdge $edge): bool => isset($body[$edge->target]),
        ));

        return ['graph' => new FlowGraph($nodes, $edges, $graph->inputs), 'entries' => $entries];
    }

    /**
     * @param array<string,list<string>> $adjacency
     * @param list<string> $starts
     * @return array<string,true>
     */
    private function reachable(array $adjacency, array $starts): array
    {
        $seen = [];
        $queue = $starts;
        $cursor = 0;

        while (isset($queue[$cursor])) {
            $id = $queue[$cursor++];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            foreach ($adjacency[$id] ?? [] as $target) {
                $queue[] = $target;
            }
        }

        return $seen;
    }

    /** @param array<string,mixed> $resumeOutputs @return array<string,mixed> */
    private function iterationResumeOutputs(array $resumeOutputs, int $index): array
    {
        $prefix = $index.'/';
        $nested = [];

        foreach ($resumeOutputs as $address => $value) {
            if (is_string($address) && str_starts_with($address, $prefix)) {
                $nested[substr($address, strlen($prefix))] = $value;
            }
        }

        return $nested;
    }
}
