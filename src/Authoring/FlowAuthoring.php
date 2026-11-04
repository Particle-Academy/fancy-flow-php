<?php

declare(strict_types=1);

namespace FancyFlow\Authoring;

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\KindId;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\ImportIssue;
use FancyFlow\Schema\WorkflowMetadata;
use FancyFlow\Workflow;

/**
 * The deterministic authoring core behind `fancy-flow-mcp`'s tools and any other surface — a thin wrapper that turns
 * "build a flow" verbs into mutations of a {@see DraftWorkflow}, delegating ALL
 * domain knowledge to fancy-flow-php:
 *
 *   - kind catalogue / config schema / defaults / config validation
 *       → {@see NodeKindRegistry}
 *   - whole-graph validation + WorkflowSchema export
 *       → {@see Workflow::import()} / {@see Workflow::export()}
 *   - execution → {@see FlowRunner} with the batteries-included default executors
 *
 * The only logic that lives HERE and not in fancy-flow-php is connect-time port
 * validation ({@see PortResolver}) — because import validates that an edge's
 * endpoints exist, not that its handles are real ports. There are no LLM calls:
 * the connecting agent is the model; this layer is pure, offline, and repeatable.
 */
final class FlowAuthoring
{
    public function __construct(private readonly NodeKindRegistry $kinds) {}

    /**
     * A registry with the full authorable catalogue: the 22 built-in kinds, the
     * structural `note` / `subgraph`, and the `agent` kind — everything an agent
     * may legitimately place on a canvas. Owns its own instance (never the shared
     * global) so concurrent servers can't clobber each other's catalogue.
     */
    public static function default(): self
    {
        return self::withHostKinds(null);
    }

    /**
     * The authorable catalogue, INCLUDING the host application's own kinds.
     *
     * This is the difference between an MCP that can author the host's real
     * workflows and one that can only author ours. A host registering
     * `deal_list`, `document` or any org-scoped kind gets them in
     * `list_node_kinds` and `describe_node_kind`, and `connect_nodes` can then
     * validate their ports — which is the whole point, because an edge naming a
     * source port nothing publishes does not fail, does not warn, and delivers
     * NOTHING. `collectInputs` binds only when `"<sourceId>:<handle>"` exists.
     *
     * That failure is worse than a missing field: the downstream template is
     * completely correct and renders empty, because the payload never arrived to
     * have a field in it. Reported by a consumer who misdiagnosed two issues off
     * the back of it. An authoring API that knows the ports removes the string
     * an author can get wrong.
     *
     * **Still its own instance.** Host kinds are COPIED in rather than the host
     * registry being used directly, so concurrent servers cannot clobber each
     * other's catalogue — the reason the isolated registry existed in the first
     * place. Host kinds are registered LAST and therefore win: a host overriding
     * a builtin means it, and an authoring surface that showed the builtin
     * instead would describe ports the run does not have.
     */
    public static function withHostKinds(?NodeKindRegistry $host): self
    {
        $registry = new NodeKindRegistry();
        Builtin::register($registry, withStructural: true);
        $registry->register(NodeKind::fromArray(Builtin::agentKind()));

        foreach ($host?->all() ?? [] as $kind) {
            $registry->register($kind);
        }

        return new self($registry);
    }

    public function kinds(): NodeKindRegistry
    {
        return $this->kinds;
    }

    // ── Draft lifecycle ───────────────────────────────────────────────────────

    public function create(string $id, ?string $name = null, ?string $description = null): DraftWorkflow
    {
        return DraftWorkflow::new($id, $name, $description);
    }

    /**
     * Hydrate a draft from an existing WorkflowSchema document (JSON string or
     * decoded array), reporting the same issues an import would. Unknown-kind /
     * dangling-edge errors do NOT abort — the draft is loaded leniently so the
     * agent can repair it, and the issues are handed back verbatim.
     *
     * @param string|array<string,mixed> $schema
     * @return array{draft:DraftWorkflow,issues:list<array<string,mixed>>,ok:bool}
     */
    public function import(string $id, string|array $schema, ?string $name = null): array
    {
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            if (! is_array($decoded)) {
                throw new FlowAuthoringException('The `schema` is not valid JSON. Pass a WorkflowSchema v1 object or its JSON string.');
            }
            $schema = $decoded;
        }

        $result = Workflow::import($schema, lenient: true, registry: $this->kinds);

        $draft = DraftWorkflow::new(
            $id,
            $name ?? (isset($schema['metadata']['name']) ? (string) $schema['metadata']['name'] : null),
            isset($schema['metadata']['description']) ? (string) $schema['metadata']['description'] : null,
        );

        foreach ($result->graph->nodes as $node) {
            $draft->nodes[] = $this->nodeToArray($node);
        }
        foreach ($result->graph->edges as $edge) {
            $draft->edges[] = $this->edgeToArray($edge);
        }

        return [
            'draft' => $draft,
            'ok' => $result->ok,
            'issues' => array_map(static fn (ImportIssue $i) => $i->toArray(), $result->issues),
        ];
    }

    // ── Nodes ─────────────────────────────────────────────────────────────────

    /**
     * Add a node of `$kind`. The kind is resolved alias-aware and stored under
     * its CANONICAL id (so an exported document matches what the editor saves).
     * When no config is given the kind's schema defaults are applied; the config
     * is always run through the kind's validator and any issues are returned
     * (never fatal — required fields can be filled in later calls).
     *
     * @param array<string,mixed>|null $position {x, y}
     * @param array<string,mixed>|null $config
     * @return array{node:array<string,mixed>,issues:list<array{key:string,message:string}>}
     */
    public function addNode(
        DraftWorkflow $draft,
        string $kind,
        ?string $nodeId = null,
        ?string $label = null,
        ?array $position = null,
        ?array $config = null,
    ): array {
        $def = $this->requireKind($kind);

        $nodeId ??= $this->freshNodeId($draft, $def);
        if ($draft->nodeIndex($nodeId) !== null) {
            throw new FlowAuthoringException("A node with id \"{$nodeId}\" already exists. Pass a different `node_id` or omit it to auto-generate one.");
        }

        $config ??= $this->kinds->defaultConfigFor($def);
        $issues = $this->kinds->validateConfig($def, $config);

        $node = [
            'id' => $nodeId,
            'kind' => $def->name,
            'position' => $this->normalizePosition($position, count($draft->nodes)),
            'config' => $config,
        ];
        if ($label !== null) {
            $node['label'] = $label;
        }

        $draft->nodes[] = $node;
        $draft->touch();

        return ['node' => $node, 'issues' => $issues];
    }

    /**
     * Replace or merge a node's config, re-validating against the kind schema.
     *
     * @param array<string,mixed> $config
     * @return array{config:array<string,mixed>,issues:list<array{key:string,message:string}>,ok:bool}
     */
    public function configureNode(DraftWorkflow $draft, string $nodeId, array $config, bool $merge = true): array
    {
        $index = $draft->nodeIndex($nodeId);
        if ($index === null) {
            throw new FlowAuthoringException($this->unknownNodeMessage($draft, $nodeId));
        }

        $node = $draft->nodes[$index];
        $def = $this->kinds->get((string) ($node['kind'] ?? ''));

        $existing = is_array($node['config'] ?? null) ? $node['config'] : [];
        $next = $merge ? array_merge($existing, $config) : $config;

        $issues = $def !== null ? $this->kinds->validateConfig($def, $next) : [];

        $node['config'] = $next;
        $draft->nodes[$index] = $node;
        $draft->touch();

        return ['config' => $next, 'issues' => $issues, 'ok' => $issues === []];
    }

    /**
     * Remove a node and every edge attached to it.
     *
     * @return array{node_id:string,removed_edges:list<string>}
     */
    public function removeNode(DraftWorkflow $draft, string $nodeId): array
    {
        $index = $draft->nodeIndex($nodeId);
        if ($index === null) {
            throw new FlowAuthoringException($this->unknownNodeMessage($draft, $nodeId));
        }

        array_splice($draft->nodes, $index, 1);

        $removed = [];
        $draft->edges = array_values(array_filter($draft->edges, static function (array $edge) use ($nodeId, &$removed): bool {
            if (($edge['source'] ?? null) === $nodeId || ($edge['target'] ?? null) === $nodeId) {
                $removed[] = (string) ($edge['id'] ?? '');

                return false;
            }

            return true;
        }));
        $draft->touch();

        return ['node_id' => $nodeId, 'removed_edges' => $removed];
    }

    // ── Edges ─────────────────────────────────────────────────────────────────

    /**
     * Connect two nodes, validating that the endpoints exist and the handles are
     * real ports on their kinds (see {@see PortResolver}). Handles default to
     * `out` on the source and `in` on the target, matching the engine.
     *
     * @return array{edge:array<string,mixed>}
     */
    public function connect(
        DraftWorkflow $draft,
        string $source,
        string $target,
        ?string $sourceHandle = null,
        ?string $targetHandle = null,
        ?string $edgeId = null,
        ?string $label = null,
    ): array {
        $sourceNode = $draft->node($source);
        if ($sourceNode === null) {
            throw new FlowAuthoringException($this->unknownNodeMessage($draft, $source, role: 'source'));
        }
        $targetNode = $draft->node($target);
        if ($targetNode === null) {
            throw new FlowAuthoringException($this->unknownNodeMessage($draft, $target, role: 'target'));
        }

        $sourceKind = $this->kinds->get((string) ($sourceNode['kind'] ?? ''));
        $targetKind = $this->kinds->get((string) ($targetNode['kind'] ?? ''));

        $outs = PortResolver::outputs($sourceKind, is_array($sourceNode['config'] ?? null) ? $sourceNode['config'] : []);
        if ($outs === []) {
            throw new FlowAuthoringException("Node \"{$source}\" ({$this->kindLabel($sourceKind)}) is a terminal node with no output ports, so nothing can connect FROM it.");
        }
        $sHandle = $sourceHandle ?? 'out';
        if (! in_array($sHandle, $outs, true)) {
            throw new FlowAuthoringException("\"{$sHandle}\" is not an output port of \"{$source}\" ({$this->kindLabel($sourceKind)}). Valid `source_handle` values: ".$this->portList($outs).'.');
        }

        $ins = PortResolver::inputs($targetKind, is_array($targetNode['config'] ?? null) ? $targetNode['config'] : []);
        if ($ins === []) {
            throw new FlowAuthoringException("Node \"{$target}\" ({$this->kindLabel($targetKind)}) accepts no inputs (it is an entry/trigger node), so nothing can connect TO it.");
        }
        $tHandle = $targetHandle ?? 'in';
        if (! in_array($tHandle, $ins, true)) {
            throw new FlowAuthoringException("\"{$tHandle}\" is not an input port of \"{$target}\" ({$this->kindLabel($targetKind)}). Valid `target_handle` values: ".$this->portList($ins).'.');
        }

        $edgeId ??= $this->freshEdgeId($draft);
        if ($draft->edgeIndex($edgeId) !== null) {
            throw new FlowAuthoringException("An edge with id \"{$edgeId}\" already exists. Pass a different `edge_id` or omit it to auto-generate one.");
        }

        $edge = [
            'id' => $edgeId,
            'source' => $source,
            'target' => $target,
            'sourceHandle' => $sHandle,
            'targetHandle' => $tHandle,
        ];
        if ($label !== null) {
            $edge['label'] = $label;
        }

        $draft->edges[] = $edge;
        $draft->touch();

        return ['edge' => $edge];
    }

    /** @return array{edge_id:string} */
    public function removeEdge(DraftWorkflow $draft, string $edgeId): array
    {
        $index = $draft->edgeIndex($edgeId);
        if ($index === null) {
            $known = array_map(static fn (array $e) => (string) ($e['id'] ?? ''), $draft->edges);
            $hint = $known === [] ? 'The workflow has no edges yet.' : 'Known edge ids: '.$this->portList($known).'.';
            throw new FlowAuthoringException("No edge \"{$edgeId}\" in this workflow. {$hint}");
        }

        array_splice($draft->edges, $index, 1);
        $draft->touch();

        return ['edge_id' => $edgeId];
    }

    // ── Validate / export / run ───────────────────────────────────────────────

    /**
     * Run the draft through the real importer and report what it found, plus the
     * host capability status (does an `llm_router` / `subflow` have what it needs
     * BEFORE a run parks itself). This is the authority on dangling edges,
     * unknown kinds, and missing required config.
     *
     * @return array<string,mixed>
     */
    public function validate(DraftWorkflow $draft): array
    {
        $result = Workflow::import($this->schemaArray($draft), registry: $this->kinds);

        return [
            'ok' => $result->ok,
            'node_count' => count($draft->nodes),
            'edge_count' => count($draft->edges),
            'errors' => array_map(static fn (ImportIssue $i) => $i->toArray(), $result->errors()),
            'warnings' => array_map(static fn (ImportIssue $i) => $i->toArray(), $result->warnings()),
            'capabilities' => Capabilities::status(),
        ];
    }

    /** Export the draft as a canonical WorkflowSchema v1 JSON string. */
    public function export(DraftWorkflow $draft, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);

        return Workflow::toJson($this->toGraph($draft), $this->metadata($draft), null, $flags);
    }

    /**
     * Execute the draft with the batteries-included default executors
     * (deterministic echo/in-memory fakes — no network, no API keys). Refuses to
     * run a graph that fails import.
     *
     * @param array<string,array<string,mixed>> $initialInputs keyed by node id then port
     * @return array<string,mixed>
     */
    public function run(DraftWorkflow $draft, array $initialInputs = []): array
    {
        $import = Workflow::import($this->schemaArray($draft), registry: $this->kinds);
        if (! $import->ok) {
            return [
                'ok' => false,
                'error' => 'Workflow has validation errors — fix them (see validate_workflow) before running.',
                'errors' => array_map(static fn (ImportIssue $i) => $i->toArray(), $import->errors()),
            ];
        }

        $result = (new FlowRunner())->run(
            $import->graph,
            Builtin::executors(),
            options: new RunOptions(initialInputs: $initialInputs),
        );

        return [
            'ok' => $result->ok,
            'error' => $result->error,
            'outputs' => $result->outputs,
            'event_count' => count($result->events),
        ];
    }

    /**
     * The draft as a full WorkflowSchema v1 document (what import/export consume).
     *
     * @return array<string,mixed>
     */
    public function schemaArray(DraftWorkflow $draft): array
    {
        return Workflow::export($this->toGraph($draft), $this->metadata($draft));
    }

    // ── Kind catalogue helpers (for the list/describe tools) ──────────────────

    /**
     * Compact catalogue entry for `list_node_kinds`.
     *
     * @return array<string,mixed>
     */
    public function summarizeKind(NodeKind $kind): array
    {
        $out = [
            'name' => $kind->name,
            'category' => $kind->category,
            'label' => $kind->label,
        ];
        if ($kind->description !== null) {
            $out['description'] = $kind->description;
        }
        if ($kind->aliases !== []) {
            $out['aliases'] = $kind->aliases;
        }
        if ($kind->pausesForHuman !== null) {
            $out['pausesForHuman'] = $kind->pausesForHuman;
        }
        $out['inputs'] = PortResolver::inputs($kind, []);
        $out['outputs'] = PortResolver::outputs($kind, []);

        return $out;
    }

    /**
     * Full manifest for `describe_node_kind`, including the effective config a
     * node of this kind would carry and — the part an author actually needs —
     * the FIELDS it emits, resolved.
     *
     * @param  array<string,mixed>|null $config the node's own config, when
     *                                          describing a node that exists;
     *                                          null uses the kind's defaults
     * @return array<string,mixed>
     */
    public function describeKind(NodeKind $kind, ?array $config = null): array
    {
        $manifest = $kind->toArray();
        $effective = $config ?? $this->kinds->defaultConfigFor($kind);
        $manifest['defaultConfig'] = $this->kinds->defaultConfigFor($kind);
        $manifest['ports'] = [
            'inputs' => PortResolver::inputs($kind, $effective),
            'outputs' => PortResolver::outputs($kind, $effective),
        ];

        // RESOLVED, not the serialisation marker.
        //
        // `toArray()` writes `"dynamic"` for a config-dependent shape, because a
        // Closure cannot cross a JSON manifest. Handing that to an authoring
        // agent is useless in the one place it matters most: `llm_call` is the
        // most-referenced kind there is, and "dynamic" tells an author nothing
        // about whether `{{ in.text }}` will resolve.
        //
        // Here there is a live registry and a config, so the question CAN be
        // answered. `configDependent` stays on the reply so the agent knows the
        // answer moves when it configures the node -- an `llm_call` gains `data`
        // the moment a `response_schema` is set, and an author who cached the
        // first answer would be wrong about the second.
        $manifest['emits'] = [
            'fields' => $kind->outputShapeFor($effective),
            'relation' => $kind->emitsFor($effective),
            'expressionConfigKey' => $kind->expressionConfigKey($effective),
            'configDependent' => $kind->hasDynamicOutputShape() || $kind->emits instanceof \Closure,
        ];

        // `fields: null` and `fields: []` are DIFFERENT answers and an agent
        // must not read them alike. Spelled out, because a reply full of nulls
        // invites exactly the reassuring misreading.
        $manifest['emits']['note'] = match (true) {
            $manifest['emits']['fields'] === null && $manifest['emits']['relation'] === 'input'
                => 'Emits its input unchanged, so the fields available here are whatever the UPSTREAM node emits. Describe that node to learn them.',
            $manifest['emits']['fields'] === null && $manifest['emits']['relation'] === 'inputs-merged'
                => 'Emits the union of every input PAYLOAD\'s fields. Describe the upstream nodes to learn them.',
            $manifest['emits']['fields'] === null && $manifest['emits']['relation'] === 'input-map-merged'
                => 'Merges the raw input MAP, whose shape depends on position: at an entry point the payload\'s fields are top level, but with an inbound edge they sit under the port name.',
            $manifest['emits']['fields'] === null
                => 'NOT DECLARED — nobody has stated what this kind emits. Unknown, NOT "emits nothing": do not refuse a reference on this basis.',
            $manifest['emits']['fields'] === []
                => 'Declares that it emits no addressable fields.',
            default => null,
        };

        return $manifest;
    }

    // ── internals ─────────────────────────────────────────────────────────────

    private function requireKind(string $kind): NodeKind
    {
        $def = $this->kinds->get($kind);
        if ($def === null) {
            throw new FlowAuthoringException("Unknown node kind \"{$kind}\". Call list_node_kinds to see what is available (bare names like \"branch\" and namespaced ids both resolve).");
        }

        return $def;
    }

    private function toGraph(DraftWorkflow $draft): FlowGraph
    {
        $nodes = array_map(function (array $n): FlowNode {
            $position = is_array($n['position'] ?? null) ? $n['position'] : [];

            return new FlowNode(
                id: (string) ($n['id'] ?? ''),
                type: isset($n['kind']) ? (string) $n['kind'] : null,
                x: (float) ($position['x'] ?? 0),
                y: (float) ($position['y'] ?? 0),
                label: isset($n['label']) ? (string) $n['label'] : null,
                description: isset($n['description']) ? (string) $n['description'] : null,
                config: is_array($n['config'] ?? null) ? $n['config'] : [],
            );
        }, $draft->nodes);

        $edges = array_map(static fn (array $e): FlowEdge => new FlowEdge(
            id: (string) ($e['id'] ?? ''),
            source: (string) ($e['source'] ?? ''),
            target: (string) ($e['target'] ?? ''),
            sourceHandle: isset($e['sourceHandle']) ? (string) $e['sourceHandle'] : null,
            targetHandle: isset($e['targetHandle']) ? (string) $e['targetHandle'] : null,
            label: isset($e['label']) ? (string) $e['label'] : null,
        ), $draft->edges);

        return new FlowGraph(array_values($nodes), array_values($edges));
    }

    private function metadata(DraftWorkflow $draft): WorkflowMetadata
    {
        return WorkflowMetadata::fromArray($draft->metadata + ['id' => $draft->id]);
    }

    /** @return array<string,mixed> */
    private function nodeToArray(FlowNode $node): array
    {
        $out = [
            'id' => $node->id,
            'kind' => $node->type ?? 'custom',
            'position' => ['x' => $node->x, 'y' => $node->y],
            'config' => $node->config,
        ];
        if ($node->label !== null) {
            $out['label'] = $node->label;
        }
        if ($node->description !== null) {
            $out['description'] = $node->description;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function edgeToArray(FlowEdge $edge): array
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

    private function freshNodeId(DraftWorkflow $draft, NodeKind $kind): string
    {
        $base = KindId::bare($kind->name);
        $n = 1;
        do {
            $candidate = "{$base}-{$n}";
            $n++;
        } while ($draft->nodeIndex($candidate) !== null);

        return $candidate;
    }

    private function freshEdgeId(DraftWorkflow $draft): string
    {
        $n = count($draft->edges) + 1;
        do {
            $candidate = "edge-{$n}";
            $n++;
        } while ($draft->edgeIndex($candidate) !== null);

        return $candidate;
    }

    /**
     * @param array<string,mixed>|null $position
     * @return array{x:float,y:float}
     */
    private function normalizePosition(?array $position, int $ordinal): array
    {
        if (is_array($position) && isset($position['x'], $position['y'])) {
            return ['x' => (float) $position['x'], 'y' => (float) $position['y']];
        }

        // A readable default grid so an un-positioned graph isn't a pile at 0,0.
        return ['x' => (float) (($ordinal % 5) * 220), 'y' => (float) (intdiv($ordinal, 5) * 140)];
    }

    private function kindLabel(?NodeKind $kind): string
    {
        return $kind === null ? 'unknown kind' : $kind->label;
    }

    /** @param list<string> $ports */
    private function portList(array $ports): string
    {
        return implode(', ', array_map(static fn (string $p) => "\"{$p}\"", $ports));
    }

    private function unknownNodeMessage(DraftWorkflow $draft, string $nodeId, ?string $role = null): string
    {
        $known = array_map(static fn (array $n) => (string) ($n['id'] ?? ''), $draft->nodes);
        $which = $role === null ? '' : "{$role} ";
        $hint = $known === [] ? 'The workflow has no nodes yet — add one with add_node.' : 'Known node ids: '.$this->portList($known).'.';

        return "No {$which}node \"{$nodeId}\" in this workflow. {$hint}";
    }
}
