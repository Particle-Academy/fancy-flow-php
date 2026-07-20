<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Capabilities\WorkflowResolutionFailure;
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
 *
 * Unless the caller PINS one. A pinned version that no longer exists is
 * reported as a mismatch naming the version that does, never as "not found":
 * the workflow is right there, and an author told it is missing goes looking in
 * the wrong place.
 */
final class EloquentWorkflowResolver implements WorkflowResolver
{
    public function __construct(private readonly NodeKindRegistry $kinds) {}

    public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
    {
        $model = $this->find($ref, $version);

        if ($model === null) {
            if ($version === null) {
                return null;
            }

            // Distinguish "no such workflow" from "exists, but not that version".
            $current = $this->find($ref);

            return $current === null
                ? null
                : WorkflowResolutionFailure::versionMismatch(
                    available: (int) $current->version,
                    message: sprintf(
                        'subflow "%s" is pinned to version %d, but the host has version %d.',
                        $ref,
                        $version,
                        (int) $current->version,
                    ),
                );
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

    private function find(string $ref, ?int $version = null): ?WorkflowModel
    {
        $query = WorkflowModel::query();

        if (ctype_digit($ref)) {
            $model = $query->find((int) $ref);

            // A numeric ref names one exact row, so a pin can only agree or not.
            return $model !== null && ($version === null || (int) $model->version === $version)
                ? $model
                : null;
        }

        $query->where('name', $ref);

        if ($version !== null) {
            $query->where('version', $version);
        }

        return $query->orderByDesc('version')->orderByDesc('id')->first();
    }
}
