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
     * @param string|null $sideEffects `none` | `idempotent` | `unsafe-to-replay` — what a
     *        SECOND attempt at this node costs. Durable runs retry; declaring
     *        `unsafe-to-replay` is what pins the node to a single attempt under the
     *        per-node queue driver instead of opening a second pull request.
     * @param string|null $accent the kind's colour in authoring surfaces.
     * @param array<string,mixed> $defaultConfig config a freshly-dropped node starts with.
     * @param string|null $pausesForHuman DECLARES that this kind stops and waits for a
     *        person, and why. Only a declaration — the executor still emits the pause —
     *        but without it nothing downstream can tell a run will park rather than fail.
     * @param list<array<string,mixed>>|string|null $outputShape the FIELDS this kind
     *        emits, not its ports. Pass {@see \FancyFlow\Registry\NodeKind::DYNAMIC_OUTPUT_SHAPE}
     *        when the shape depends on config: an attribute cannot hold a closure, and
     *        the marker is how it still says "a shape exists, this process cannot
     *        resolve it". `[]` is the DIFFERENT, positive claim that it emits no fields;
     *        omitting it says nothing at all. Do not collapse those three.
     * @param string|null $emits where the emitted fields come from — `input`,
     *        `inputs-merged`, `input-map-merged`, `expression:<key>`. `outputShape`
     *        answers *which fields*; this answers *where from*.
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
        public readonly ?string $sideEffects = null,
        public readonly ?string $accent = null,
        public readonly array $defaultConfig = [],
        public readonly ?string $pausesForHuman = null,
        public readonly array|string|null $outputShape = null,
        public readonly ?string $emits = null,
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
        if ($this->sideEffects !== null) {
            $kind['sideEffects'] = $this->sideEffects;
        }
        if ($this->accent !== null) {
            $kind['accent'] = $this->accent;
        }
        if ($this->defaultConfig !== []) {
            $kind['defaultConfig'] = $this->defaultConfig;
        }
        if ($this->pausesForHuman !== null) {
            $kind['pausesForHuman'] = $this->pausesForHuman;
        }
        // Compared against null, NOT emptiness: `outputShape: []` is the
        // positive claim "this kind emits no fields", and dropping it would
        // turn that statement back into silence — which every consumer is
        // required to read as "unknown". Those are different answers.
        if ($this->outputShape !== null) {
            $kind['outputShape'] = $this->outputShape;
        }
        if ($this->emits !== null) {
            $kind['emits'] = $this->emits;
        }

        return $kind;
    }
}
