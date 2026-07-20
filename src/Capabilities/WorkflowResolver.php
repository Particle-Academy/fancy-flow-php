<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

use FancyFlow\Schema\FlowGraph;

/**
 * Resolve a workflow reference to a runnable graph.
 *
 * `subflow` NAMES another workflow rather than embedding it, so the host owns
 * where workflows live — a database, a file, an API.
 *
 * ## Why $version is here
 *
 * A workflow another workflow depends on is an INTERFACE, and interfaces need
 * pins. Without a version, a parent goes on calling `invoice-triage`, someone
 * edits that child, and the parent now runs different logic HAVING REPORTED
 * SUCCESS THE WHOLE TIME — correct-looking, no error, wrong behaviour. The same
 * failure family as the 0.9.0 routing divergence.
 *
 * The parameter lives here rather than being encoded into the ref string
 * (`invoice-triage@3`) because a stringly-typed protocol is one every host
 * invents differently — the "three vocabularies for one node" problem.
 *
 * Raised by the MOIC Suite consumer, whose `workflow_ref` pins versions and
 * fails loudly on mismatch. Their point: a host COULD NOT implement pinning
 * before this, because the node had no way to ask and the resolver no way to
 * receive.
 *
 * Returning null still means "no such workflow". Return a
 * {@see WorkflowResolutionFailure} to distinguish a version mismatch — the two
 * want different errors.
 *
 * BREAKING for implementers (not callers) as of 0.8.0. Done while the
 * population of implementers was ~1; later it would not have been.
 *
 * The PHP twin of fancy-flow's `WorkflowResolver`.
 */
interface WorkflowResolver
{
    public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null;
}
