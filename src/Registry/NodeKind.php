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
    ) {}

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

        return $out;
    }
}
