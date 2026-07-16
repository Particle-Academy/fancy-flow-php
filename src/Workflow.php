<?php

declare(strict_types=1);

namespace FancyFlow;

use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\ImportIssue;
use FancyFlow\Schema\ImportResult;
use FancyFlow\Schema\WorkflowMetadata;

/**
 * Parse, validate, import, and export WorkflowSchema v1 documents — the PHP
 * twin of fancy-flow's `importWorkflow` / `exportWorkflow`. A graph an agent or
 * human authors in `<FlowEditor>` round-trips through here unchanged.
 */
final class Workflow
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_URL = 'https://particle.academy/schemas/workflow/v1.json';

    /**
     * Hydrate a WorkflowSchema (a JSON string or a decoded array) into a
     * {@see FlowGraph}, validating kinds and configs against the registry.
     * Reports issues for unknown kinds, missing required config, and dangling
     * edges. In lenient mode, schema-level errors become warnings.
     *
     * @param string|array<string,mixed> $schema
     */
    public static function import(
        string|array $schema,
        bool $lenient = false,
        ?NodeKindRegistry $registry = null,
    ): ImportResult {
        $registry ??= NodeKindRegistry::default();
        $issues = [];

        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($schema)) {
            return new ImportResult(
                false,
                new FlowGraph(),
                [ImportIssue::error('Schema is not an object.')],
            );
        }

        $version = $schema['version'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            $issues[] = new ImportIssue(
                $lenient ? ImportIssue::WARNING : ImportIssue::ERROR,
                sprintf(
                    'Unsupported workflow schema version: %s (expected %d)',
                    var_export($version, true),
                    self::SCHEMA_VERSION,
                ),
            );
            if (! $lenient) {
                return new ImportResult(false, new FlowGraph(), $issues);
            }
        }

        $rawNodes = $schema['graph']['nodes'] ?? [];
        $rawEdges = $schema['graph']['edges'] ?? [];

        $nodes = [];
        $nodeIds = [];
        foreach ($rawNodes as $raw) {
            $kindName = (string) ($raw['kind'] ?? '');
            $kind = $registry->get($kindName);

            if ($kind === null) {
                $issues[] = new ImportIssue(
                    $lenient ? ImportIssue::WARNING : ImportIssue::ERROR,
                    "Unknown kind \"{$kindName}\" — register it before importing.",
                    nodeId: $raw['id'] ?? null,
                );
            }

            $config = $raw['config'] ?? ($kind !== null ? $registry->defaultConfigFor($kind) : []);

            if ($kind !== null) {
                foreach ($registry->validateConfig($kind, $config) as $iss) {
                    $issues[] = ImportIssue::warning("{$iss['key']}: {$iss['message']}", nodeId: $raw['id'] ?? null);
                }
            }

            $node = new FlowNode(
                id: (string) $raw['id'],
                type: $kindName,
                x: (float) ($raw['position']['x'] ?? 0),
                y: (float) ($raw['position']['y'] ?? 0),
                label: $raw['label'] ?? $kind?->label ?? $kindName,
                description: isset($raw['description']) ? (string) $raw['description'] : null,
                config: $config,
                // inputs/outputs intentionally left null on import — the engine
                // then defaults to a single `out` port, matching the TS import.
            );
            $nodes[] = $node;
            $nodeIds[$node->id] = true;
        }

        $edges = [];
        foreach ($rawEdges as $raw) {
            $id = (string) ($raw['id'] ?? '');
            $source = (string) ($raw['source'] ?? '');
            $target = (string) ($raw['target'] ?? '');

            if (! isset($nodeIds[$source])) {
                $issues[] = ImportIssue::warning("Edge source \"{$source}\" not found.", edgeId: $id);

                continue;
            }
            if (! isset($nodeIds[$target])) {
                $issues[] = ImportIssue::warning("Edge target \"{$target}\" not found.", edgeId: $id);

                continue;
            }

            $edges[] = new FlowEdge(
                id: $id,
                source: $source,
                target: $target,
                sourceHandle: isset($raw['sourceHandle']) ? (string) $raw['sourceHandle'] : null,
                targetHandle: isset($raw['targetHandle']) ? (string) $raw['targetHandle'] : null,
                label: isset($raw['label']) && is_string($raw['label']) ? $raw['label'] : null,
            );
        }

        $ok = true;
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                $ok = false;

                break;
            }
        }

        return new ImportResult($ok, new FlowGraph($nodes, $edges), $issues);
    }

    /**
     * Snapshot an in-memory graph as a portable WorkflowSchema array. When
     * `$metadata` is supplied its `updatedAt` is stamped with the current time
     * (ms), mirroring `exportWorkflow`.
     *
     * @param array{viewport?:array{x:float,y:float,zoom:float}}|null $view
     * @return array<string,mixed>
     */
    public static function export(FlowGraph $graph, ?WorkflowMetadata $metadata = null, ?array $view = null): array
    {
        $schema = [
            '$schema' => self::SCHEMA_URL,
            'version' => self::SCHEMA_VERSION,
        ];

        if ($metadata !== null) {
            $meta = $metadata->toArray();
            $meta['updatedAt'] = (int) round(microtime(true) * 1000);
            $schema['metadata'] = $meta;
        }

        $schema['graph'] = [
            'nodes' => array_map(self::toSchemaNode(...), $graph->nodes),
            'edges' => array_map(self::toSchemaEdge(...), $graph->edges),
        ];

        if ($view !== null) {
            $schema['view'] = $view;
        }

        return $schema;
    }

    /** Export + JSON-encode in one step. */
    public static function toJson(FlowGraph $graph, ?WorkflowMetadata $metadata = null, ?array $view = null, int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode(self::export($graph, $metadata, $view), $flags | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function toSchemaNode(FlowNode $node): array
    {
        $out = [
            'id' => $node->id,
            'kind' => $node->type ?? 'custom',
            'position' => ['x' => $node->x, 'y' => $node->y],
        ];
        if ($node->label !== null) {
            $out['label'] = $node->label;
        }
        if ($node->description !== null) {
            $out['description'] = $node->description;
        }
        if ($node->config !== []) {
            $out['config'] = $node->config;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function toSchemaEdge(FlowEdge $edge): array
    {
        $out = [
            'id' => $edge->id,
            'source' => $edge->source,
            'target' => $edge->target,
        ];
        if ($edge->sourceHandle !== null) {
            $out['sourceHandle'] = $edge->sourceHandle;
        }
        if ($edge->targetHandle !== null) {
            $out['targetHandle'] = $edge->targetHandle;
        }
        if ($edge->label !== null) {
            $out['label'] = $edge->label;
        }

        return $out;
    }
}
