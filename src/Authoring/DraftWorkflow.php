<?php

declare(strict_types=1);

namespace FancyFlow\Authoring;

/**
 * The mutable in-progress workflow an agent authors across many MCP tool calls.
 *
 * It is stored between calls (see a host's own store; `fancy-flow-mcp` ships one) as a
 * plain array in the exact shape of a fancy-flow WorkflowSchema v1 `graph`
 * block — nodes carry `kind` / `position` / `config`, edges carry
 * `source` / `target` / `sourceHandle` / `targetHandle` — so
 * {@see \FancyFlow\Workflow::import()} / `::export()` round-trip it with zero
 * translation.
 *
 * This object owns identity and shape only. All validation (kinds, config,
 * ports, whole-graph) lives in {@see FlowAuthoring}, which reuses fancy-flow-php.
 */
final class DraftWorkflow
{
    /**
     * @param array<string,mixed>        $metadata name/description/createdAt/updatedAt/…
     * @param list<array<string,mixed>>  $nodes    WorkflowSchema nodes ({id, kind, position, config, …})
     * @param list<array<string,mixed>>  $edges    WorkflowSchema edges ({id, source, target, …})
     */
    public function __construct(
        public string $id,
        public array $metadata = [],
        public array $nodes = [],
        public array $edges = [],
    ) {}

    public static function new(string $id, ?string $name = null, ?string $description = null): self
    {
        $now = self::nowMs();
        $metadata = ['id' => $id, 'createdAt' => $now, 'updatedAt' => $now];
        if ($name !== null) {
            $metadata['name'] = $name;
        }
        if ($description !== null) {
            $metadata['description'] = $description;
        }

        return new self($id, $metadata);
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: (string) ($raw['id'] ?? ''),
            metadata: is_array($raw['metadata'] ?? null) ? $raw['metadata'] : [],
            nodes: array_values(is_array($raw['nodes'] ?? null) ? $raw['nodes'] : []),
            edges: array_values(is_array($raw['edges'] ?? null) ? $raw['edges'] : []),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'metadata' => $this->metadata,
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
        ];
    }

    /**
     * The node with this id, or null.
     *
     * @return array<string,mixed>|null
     */
    public function node(string $id): ?array
    {
        $i = $this->nodeIndex($id);

        return $i === null ? null : $this->nodes[$i];
    }

    public function nodeIndex(string $id): ?int
    {
        foreach ($this->nodes as $i => $node) {
            if (($node['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    public function edgeIndex(string $id): ?int
    {
        foreach ($this->edges as $i => $edge) {
            if (($edge['id'] ?? null) === $id) {
                return $i;
            }
        }

        return null;
    }

    /** Stamp the metadata as freshly touched. */
    public function touch(): void
    {
        $this->metadata['updatedAt'] = self::nowMs();
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
