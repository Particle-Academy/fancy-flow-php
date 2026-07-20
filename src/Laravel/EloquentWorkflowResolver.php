<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Laravel\Models\Workflow as WorkflowModel;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Workflow;

/**
 * Resolves a `subflow` reference against the stored workflows table.
 *
 * The framework-free core only declares {@see WorkflowResolver} — where
 * workflows live is the host's business. Under Laravel with persistence on,
 * they already live in `fancy_flow_workflows`, so this ships as the default and
 * `subflow` works without the app writing any glue. Bind your own
 * {@see WorkflowResolver} to point it somewhere else.
 *
 * A reference is a workflow `name`, or a numeric id. When several rows share a
 * name the HIGHEST `version` wins — referencing "onboarding" should mean the
 * current onboarding flow, not whichever row was inserted first.
 */
final class EloquentWorkflowResolver implements WorkflowResolver
{
    public function __construct(private readonly NodeKindRegistry $kinds) {}

    public function resolve(string $ref): ?FlowGraph
    {
        $model = $this->find($ref);
        if ($model === null) {
            return null;
        }

        $schema = $model->schema;
        if (! is_array($schema)) {
            return null;
        }

        // Lenient: a stored graph referencing a kind this runtime doesn't know
        // is a warning, and the run then fails loudly at the unknown node —
        // better than the whole subflow silently resolving to nothing.
        return Workflow::import($schema, lenient: true, registry: $this->kinds)->graph;
    }

    private function find(string $ref): ?WorkflowModel
    {
        $query = WorkflowModel::query();

        if (ctype_digit($ref)) {
            return $query->find((int) $ref);
        }

        return $query->where('name', $ref)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }
}
