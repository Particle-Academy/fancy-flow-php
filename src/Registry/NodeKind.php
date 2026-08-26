<?php

declare(strict_types=1);

namespace FancyFlow\Registry;

use FancyFlow\Schema\PortDescriptor;

/**
 * Declares an authorable node type — its shape, ports, and config schema.
 * The PHP twin of fancy-flow's `NodeKindDefinition` (minus the React render
 * hooks, which have no server-side meaning). Drives import validation and is
 * the surface a shared kind manifest round-trips through.
 *
 * `$inputs` / `$outputs` are nullable to preserve the "not declared" vs
 * "declared empty" distinction the engine reads (see {@see \FancyFlow\Schema\FlowNode}).
 */
final class NodeKind
{
    /**
     * @param list<ConfigField>         $configSchema
     * @param array<string,mixed>       $defaultConfig
     * @param list<PortDescriptor>|null $inputs
     * @param list<PortDescriptor>|null $outputs
     * @param list<string>              $aliases previous ids this kind still answers to
     */
    public function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly string $label,
        public readonly ?string $description = null,
        public readonly ?string $icon = null,
        public readonly ?string $accent = null,
        public readonly array $configSchema = [],
        public readonly array $defaultConfig = [],
        public readonly ?array $inputs = null,
        public readonly ?array $outputs = null,
        public readonly array $aliases = [],
        /**
         * Declares that this kind halts the run to wait for a person, and what
         * for — `approval`, `input`, or a node's own (`signature`, `payment`).
         *
         * Only a declaration; the executor still emits the pause. Its value is
         * that it is readable WITHOUT running the graph, so a host learns it
         * needs a resume path before the first run parks itself forever.
         */
        public readonly ?string $pausesForHuman = null,
        /**
         * What re-running this node costs — `none`, `idempotent`, or
         * `unsafe-to-replay`. The same vocabulary a node package declares in its
         * {@see \FancyFlow\Marketplace\NodeManifest}, lifted onto the kind so it
         * is readable from the registry without loading a manifest.
         *
         * A durable run RETRIES. `unsafe-to-replay` is the node saying a second
         * attempt is not a repeat of the first — `git_pr_open` opens a second
         * pull request. The per-node queue driver reads this and pins such a
         * node to a single attempt; nothing else in the engine consults it.
         */
        public readonly ?string $sideEffects = null,
        /**
         * The FIELDS this kind emits — not its ports. `{{ in.text }}` is a
         * field; `outputs` is where an edge attaches. They are different
         * questions and only this one can answer "does that field exist".
         *
         * Three states, and the third is the reason this is nullable:
         *   - `null`      — NOT DECLARED. Nobody has said. Unknown.
         *   - `[]`        — declares that it emits no fields.
         *   - a list      — `[['path' => 'text', 'type' => 'string'], ...]`
         *
         * Collapsing `null` into `[]` is the bug this field was added to fix.
         * A consumer reading "no shape" as "emits nothing" refuses a legitimate
         * `{{ in.title }}`, and a false rejection is one the author cannot
         * comply with.
         *
         * **A Closure is a first-class form, not an escape hatch.** A
         * `user_input` emits the keys its author defined and a `system_event`
         * its event's payload; no static list can know either. The closure
         * receives the node's own config and returns the field list. Use
         * {@see outputShapeFor()} rather than reading this property, so both
         * forms resolve the same way.
         *
         * @var list<array{path:string,type?:string,description?:string}>|\Closure|null
         */
        public readonly array|\Closure|null $outputShape = null,
    ) {}

    /**
     * The fields this kind emits for a given config, or `null` when nothing has
     * been declared.
     *
     * Always prefer this to reading `$outputShape`: it resolves the static and
     * closure forms identically, so a caller cannot accidentally handle only
     * the one it happened to meet first.
     *
     * @param  array<string,mixed> $config
     * @return list<array{path:string,type?:string,description?:string}>|null
     */
    public function outputShapeFor(array $config): ?array
    {
        if ($this->outputShape === null) {
            return null;
        }
        if ($this->outputShape instanceof \Closure) {
            return ($this->outputShape)($config);
        }

        return $this->outputShape;
    }

    /**
     * True when the shape depends on config and therefore cannot be serialised.
     *
     * A manifest reader needs this to tell "config-dependent, resolve it
     * in-process" from "nothing declared" — which is the same absent-vs-empty
     * distinction one level down, at the serialisation seam.
     */
    public function hasDynamicOutputShape(): bool
    {
        return $this->outputShape instanceof \Closure || $this->outputShape === self::DYNAMIC_OUTPUT_SHAPE;
    }

    /**
     * What `toArray()` writes for a closure-backed shape.
     *
     * A closure cannot cross a JSON boundary. Dropping it would make the
     * manifest say "no outputShape", which reads as "emits nothing" — exactly
     * the failure this field exists to prevent, reintroduced at the point the
     * kind is written down. So the manifest says DYNAMIC instead: a reader
     * learns a shape exists and that it must ask the runtime for it.
     */
    public const DYNAMIC_OUTPUT_SHAPE = 'dynamic';

    /**
     * Every id this kind answers to — canonical first.
     *
     * Anything keyed by kind name (executor bindings, node-type maps) must key
     * on ALL of these: a host that bound an executor under the bare name has to
     * keep working, or a rename is a breaking change in disguise.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_unique([$this->name, ...$this->aliases]));
    }

    /**
     * Hydrate from an array literal (the shape used by the built-in library and
     * the shared kind manifest). `configSchema`, `inputs`, `outputs` accept raw
     * arrays and are converted to value objects.
     *
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            name: (string) $raw['name'],
            category: (string) ($raw['category'] ?? 'custom'),
            label: (string) ($raw['label'] ?? $raw['name']),
            description: isset($raw['description']) ? (string) $raw['description'] : null,
            icon: isset($raw['icon']) ? (string) $raw['icon'] : null,
            accent: isset($raw['accent']) ? (string) $raw['accent'] : null,
            configSchema: array_values(array_map(
                static fn (array $f) => ConfigField::fromArray($f),
                $raw['configSchema'] ?? [],
            )),
            defaultConfig: $raw['defaultConfig'] ?? [],
            inputs: self::ports($raw, 'inputs'),
            outputs: self::ports($raw, 'outputs'),
            aliases: array_values(array_map(
                static fn (mixed $a): string => (string) $a,
                is_array($raw['aliases'] ?? null) ? $raw['aliases'] : [],
            )),
            pausesForHuman: isset($raw['pausesForHuman']) ? (string) $raw['pausesForHuman'] : null,
            sideEffects: isset($raw['sideEffects']) ? (string) $raw['sideEffects'] : null,
            // A manifest that says DYNAMIC comes back as a closure yielding
            // null: "a shape exists, and this process cannot resolve it".
            // Storing the marker string instead would push the decision onto
            // every caller, and the caller that forgets reads it as a field
            // list -- the failure this whole field exists to prevent.
            outputShape: ($raw['outputShape'] ?? null) === self::DYNAMIC_OUTPUT_SHAPE
                ? static fn (array $config): ?array => null
                : $raw['outputShape'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return list<PortDescriptor>|null
     */
    private static function ports(array $raw, string $key): ?array
    {
        if (! array_key_exists($key, $raw) || ! is_array($raw[$key])) {
            return null;
        }

        return array_values(array_map(
            static fn (array $p) => PortDescriptor::fromArray($p),
            $raw[$key],
        ));
    }

    /** @return array<string,mixed> A manifest-friendly serialization. */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'category' => $this->category,
            'label' => $this->label,
        ];
        foreach (['description' => $this->description, 'icon' => $this->icon, 'accent' => $this->accent] as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        if ($this->configSchema !== []) {
            $out['configSchema'] = array_map(static fn (ConfigField $f) => $f->toArray(), $this->configSchema);
        }
        if ($this->defaultConfig !== []) {
            $out['defaultConfig'] = $this->defaultConfig;
        }
        if ($this->inputs !== null) {
            $out['inputs'] = array_map(static fn (PortDescriptor $p) => $p->toArray(), $this->inputs);
        }
        if ($this->outputs !== null) {
            $out['outputs'] = array_map(static fn (PortDescriptor $p) => $p->toArray(), $this->outputs);
        }
        if ($this->aliases !== []) {
            $out['aliases'] = $this->aliases;
        }
        if ($this->pausesForHuman !== null) {
            $out['pausesForHuman'] = $this->pausesForHuman;
        }
        if ($this->outputShape !== null) {
            // A closure is written down as DYNAMIC rather than omitted: absent
            // would read as "emits nothing", which is a legitimate answer and
            // therefore an invisible loss.
            $out['outputShape'] = $this->outputShape instanceof \Closure
                ? self::DYNAMIC_OUTPUT_SHAPE
                : $this->outputShape;
        }
        if ($this->sideEffects !== null) {
            $out['sideEffects'] = $this->sideEffects;
        }

        return $out;
    }
}
