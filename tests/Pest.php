<?php

declare(strict_types=1);

use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;

/**
 * Pest configuration for fancy-flow-php.
 *
 * Tests under `tests/Unit/` and `tests/Parity/` run against pure PHP — the
 * framework-free core. The Laravel integration tests (Orchestra Testbench)
 * arrive with the 0.2 Laravel layer under `tests/Laravel/`.
 */

uses()->in(__DIR__.'/Unit');
uses()->in(__DIR__.'/Parity');

/**
 * Build a FlowNode tersely.
 *
 * @param array<string,mixed>    $config
 * @param list<string>|null      $outputs port ids
 * @param list<string>|null      $inputs  port ids
 */
function ffNode(string $id, ?string $type = null, array $config = [], ?array $outputs = null, ?array $inputs = null): FlowNode
{
    return new FlowNode(
        id: $id,
        type: $type,
        config: $config,
        inputs: $inputs === null ? null : array_map(static fn (string $p) => new PortDescriptor($p), $inputs),
        outputs: $outputs === null ? null : array_map(static fn (string $p) => new PortDescriptor($p), $outputs),
    );
}

function ffEdge(string $id, string $source, string $target, ?string $sourceHandle = null, ?string $targetHandle = null): FlowEdge
{
    return new FlowEdge($id, $source, $target, sourceHandle: $sourceHandle, targetHandle: $targetHandle);
}

/**
 * @param list<FlowNode> $nodes
 * @param list<FlowEdge> $edges
 */
function ffGraph(array $nodes, array $edges = []): FlowGraph
{
    return new FlowGraph($nodes, $edges);
}
