<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

use FancyFlow\Schema\FlowGraph;

/**
 * Resolve a workflow reference to a runnable graph.
 *
 * `subflow` NAMES another workflow rather than embedding it, so the host owns
 * where workflows live — a database, a file, an API. Returning null means "no
 * such workflow", which the node reports as an error rather than silently
 * running nothing.
 *
 * The PHP twin of fancy-flow's `WorkflowResolver`.
 */
interface WorkflowResolver
{
    public function resolve(string $ref): ?FlowGraph;
}
