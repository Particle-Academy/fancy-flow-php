<?php

declare(strict_types=1);

namespace FancyFlow;

use FancyFlow\Analysis\GraphConnectivity;
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
    /**
     * Every migration step, keyed by the version it upgrades FROM.
     *
     * A step keyed `N` takes a version-N document to version N+1. Empty today
     * because v1 is current -- when a BREAKING bump lands, add the step here and
     * every stored document upgrades on read, in this runtime and its twins.
     *
     * @return array<int, callable(array<string,mixed>): array<string,mixed>>
     */
    private static function migrations(): array
    {
        return [];
    }

    /**
     * Upgrade a schema document to the current version, as far as it can go.
     *
     * ## Why this exists, and why it had to exist BEFORE it was needed
     *
     * The version has always been on the document; only the TypeScript runtime
     * acted on it. This runtime and the Python one compared it and errored -- so
     * the day schema v2 was cut, every stored Op would have hard-failed to
     * import on both SERVER runtimes, which is where durable runs RESUME. A run
     * parked on a human approval would have become unresumable, and the fix
     * could not be applied afterwards: the graphs would already be unreadable by
     * the very code meant to migrate them.
     *
     * ## The three rules, each with a reason
     *
     *  - **A PAST version migrates forward**, step by step, until it reaches the
     *    current one.
     *  - **A FUTURE version is left ALONE.** We cannot know what a later schema
     *    means, and migrating downward would be guessing. Untouched hands it to
     *    the version check, which reports it honestly.
     *  - **A GAP in the table is left alone too.** A missing step is not a
     *    licence to guess; the document reaches the version check unchanged.
     *
     * Nothing here changes behaviour today -- with an empty table every document
     * passes through untouched -- which is exactly the property that makes it
     * safe to add now rather than under pressure later.
     *
     * `$steps` is an argument rather than a hard-coded lookup because otherwise
     * this seam could not be TESTED: with only v1 in existence there is no old
     * document to migrate, and a test against the built-in table would pass
     * identically against a `migrate()` that did nothing at all.
     *
     * @param  array<string,mixed>  $schema
     * @param  array<int, callable(array<string,mixed>): array<string,mixed>>|null  $steps
     * @return array<string,mixed>
     */
    public static function migrate(array $schema, ?array $steps = null): array
    {
        $steps ??= self::migrations();
        $version = $schema['version'] ?? null;

        if (! is_int($version) || $version >= self::SCHEMA_VERSION) {
            return $schema;
        }

        while ($version < self::SCHEMA_VERSION) {
            if (! isset($steps[$version])) {
                return $schema;
            }

            $schema = ($steps[$version])($schema);
            $version++;
            $schema['version'] = $version;
        }

        return $schema;
    }

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

        // Best-effort forward migration BEFORE the version check, so a document
        // written against an older schema is upgraded rather than rejected. The
        // check below is still the gate: anything migration could not resolve
        // reaches it unchanged and is reported exactly as it was before.
        $schema = self::migrate($schema);

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
                startingMsg: isset($raw['startingMsg']) ? (string) $raw['startingMsg'] : null,
                stoppingMsg: isset($raw['stoppingMsg']) ? (string) $raw['stoppingMsg'] : null,
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

        // WIRING, not merely dataflow: a node that no edge reaches and that
        // reaches no edge, and an edge that reads from a node publishing nothing.
        //
        // Deliberately AFTER the edge loop, so it sees the same edges the engine
        // will -- a dangling edge is dropped with a warning above, and running
        // this first would let a dropped edge count as a connection.
        //
        // Deliberately NOT gated on `$lenient`. That flag is about unknown
        // VOCABULARY (a kind this host has not registered), never about wiring;
        // a floating node floats in every registry.
        foreach (GraphConnectivity::check($nodes, $edges, $registry) as $issue) {
            $issues[] = $issue;
        }

        $ok = true;
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                $ok = false;

                break;
            }
        }

        // `graph.inputs` is what the workflow ACCEPTS -- the declaration
        // `RunOptions::$props` is validated against. Dropping it here meant
        // every imported graph declared nothing, so `WorkflowProps::resolve`
        // rejected every prop with "this workflow declares no inputs" -- and a
        // durable run ALWAYS imports from the stored schema, which made props
        // unreachable for the only execution path a Laravel app uses.
        //
        // Only well-formed entries survive, and a malformed one is dropped
        // rather than aborting the import: a bad declaration should not cost a
        // consumer their whole graph, and `resolve()` is where a value is
        // judged anyway.
        $declaredInputs = [];
        foreach (($schema['graph']['inputs'] ?? []) as $input) {
            if (is_array($input) && is_string($input['name'] ?? null) && $input['name'] !== '') {
                $declaredInputs[] = $input;
            }
        }

        return new ImportResult($ok, new FlowGraph($nodes, $edges, $declaredInputs), $issues);
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
            // Written only when there IS a declaration, matching the TypeScript
            // exporter. An always-present `"inputs": []` would change the bytes
            // of every graph ever saved, for nothing.
            ...($graph->inputs !== [] ? ['inputs' => $graph->inputs] : []),
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
        // Omitted entirely when unset, so a graph of ordinary plumbing nodes
        // does not carry a pair of empty keys per node and every diff of a
        // saved graph stays readable.
        if ($node->startingMsg !== null && trim($node->startingMsg) !== '') {
            $out['startingMsg'] = $node->startingMsg;
        }
        if ($node->stoppingMsg !== null && trim($node->stoppingMsg) !== '') {
            $out['stoppingMsg'] = $node->stoppingMsg;
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
