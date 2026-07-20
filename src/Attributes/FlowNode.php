<?php

declare(strict_types=1);

namespace FancyFlow\Attributes;

use Attribute;

/**
 * Marks an executor class as a flow node kind, so the Laravel layer's
 * `flow:discover` can auto-register BOTH the kind (shape) and the executor
 * (behavior) in one place:
 *
 *     #[FlowNode('geocode', category: 'io', label: 'Geocode')]
 *     final class GeocodeExecutor implements NodeExecutor { ... }
 *
 * The attribute carries the lightweight kind metadata; richer kinds (ports,
 * config schema) are still declared via {@see \FancyFlow\Registry\NodeKind} in
 * config when needed. Framework-free — the attribute lives in the core so the
 * class it annotates carries its identity everywhere.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class FlowNode
{
    /**
     * @param list<array<string,mixed>> $configSchema
     * @param list<array<string,mixed>>|null $inputs
     * @param list<array<string,mixed>>|null $outputs
     * @param list<string> $aliases previous ids this kind still answers to. `name` is
     *        CANONICAL and is what lands in saved graphs, so publish namespaced
     *        (`@acme/salesforce_upsert`) and keep the old bare name here.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $category = 'custom',
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly array $configSchema = [],
        public readonly ?array $inputs = null,
        public readonly ?array $outputs = null,
        public readonly array $aliases = [],
    ) {}

    /**
     * Every id this kind answers to — canonical first.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_unique([$this->name, ...$this->aliases]));
    }

    /** The kind definition array this attribute describes (feeds NodeKind::fromArray). */
    public function toKindArray(): array
    {
        $kind = [
            'name' => $this->name,
            'category' => $this->category,
            'label' => $this->label ?? $this->name,
        ];
        if ($this->description !== null) {
            $kind['description'] = $this->description;
        }
        if ($this->icon !== null) {
            $kind['icon'] = $this->icon;
        }
        if ($this->configSchema !== []) {
            $kind['configSchema'] = $this->configSchema;
        }
        if ($this->inputs !== null) {
            $kind['inputs'] = $this->inputs;
        }
        if ($this->outputs !== null) {
            $kind['outputs'] = $this->outputs;
        }
        if ($this->aliases !== []) {
            $kind['aliases'] = $this->aliases;
        }

        return $kind;
    }
}
