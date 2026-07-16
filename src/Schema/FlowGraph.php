<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A serializable graph — nodes + edges. The unit both hosts persist and the
 * {@see \FancyFlow\Engine\FlowRunner} executes. Mirrors fancy-flow's `FlowGraph`.
 */
final class FlowGraph
{
    /**
     * @param list<FlowNode> $nodes
     * @param list<FlowEdge> $edges
     */
    public function __construct(
        public readonly array $nodes = [],
        public readonly array $edges = [],
    ) {}

    public function node(string $id): ?FlowNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }
}
