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
     * @param list<FlowNode>              $nodes
     * @param list<FlowEdge>              $edges
     * @param list<array<string,mixed>>   $inputs What this workflow ACCEPTS at run start —
     *                                            `{name, type?, required?, default?}` per entry.
     *                                            Callers pass a flat object BY NAME rather than
     *                                            keyed by node id, so renaming a trigger no
     *                                            longer breaks every caller silently.
     *                                            Empty for a workflow that takes none, which is
     *                                            every graph saved before this existed.
     */
    public function __construct(
        public readonly array $nodes = [],
        public readonly array $edges = [],
        public readonly array $inputs = [],
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
