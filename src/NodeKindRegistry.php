<?php

declare(strict_types=1);

namespace FancyFlow;

use FancyFlow\Registry\ConfigField;
use FancyFlow\Registry\NodeKind;

/**
 * The catalogue of authorable node kinds. The PHP twin of fancy-flow's module
 * registry (register / get / list / defaultConfigFor / validateConfig).
 *
 * The TS registry is a module-global `Map`; here the closest analogue is the
 * shared {@see default()} instance, which {@see Workflow::import()} validates
 * against by default. Registries are also instantiable so tests (and hosts
 * wanting an isolated catalogue) can keep their own.
 */
final class NodeKindRegistry
{
    /** @var array<string, NodeKind> keyed by CANONICAL id. */
    private array $kinds = [];

    /** @var array<string, string> alias → canonical id. See {@see resolveKindId()}. */
    private array $aliases = [];

    private static ?self $default = null;

    /** The shared registry — the analogue of the TS module-global. */
    public static function default(): self
    {
        return self::$default ??= new self();
    }

    /** Reset the shared registry. Handy for test isolation. */
    public static function resetDefault(): void
    {
        self::$default = null;
    }

    /** Install a kind (replacing any prior registration of the same name). */
    public function register(NodeKind $kind): static
    {
        $this->kinds[$kind->name] = $kind;
        foreach ($kind->aliases as $alias) {
            $this->aliases[$alias] = $kind->name;
        }

        return $this;
    }

    /** Remove a kind by any of its ids, along with every alias pointing at it. */
    public function unregister(string $name): void
    {
        $canonical = $this->resolveKindId($name) ?? $name;
        unset($this->kinds[$canonical]);
        foreach ($this->aliases as $alias => $target) {
            if ($target === $canonical) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * Resolve any id — canonical or alias — to the canonical one, or null.
     *
     * The PHP twin of fancy-flow's `resolveKindId`. `kind` is persisted inside
     * every saved graph, so documents written before namespacing must keep
     * resolving; that is exactly what aliases are for.
     */
    public function resolveKindId(string $id): ?string
    {
        if (isset($this->kinds[$id])) {
            return $id;
        }

        $canonical = $this->aliases[$id] ?? null;

        return $canonical !== null && isset($this->kinds[$canonical]) ? $canonical : null;
    }

    /** Get a kind by canonical id or alias, or null. */
    public function get(string $name): ?NodeKind
    {
        $canonical = $this->resolveKindId($name);

        return $canonical === null ? null : $this->kinds[$canonical];
    }

    public function has(string $name): bool
    {
        return $this->resolveKindId($name) !== null;
    }

    /**
     * Every id the kind registered under `$name` answers to — canonical first,
     * then its aliases. Empty when nothing is registered under that id.
     *
     * @return list<string>
     */
    public function idsFor(string $name): array
    {
        return $this->get($name)?->ids() ?? [];
    }

    /**
     * Every registered kind, optionally filtered by category.
     *
     * @return list<NodeKind>
     */
    public function all(?string $category = null): array
    {
        $all = array_values($this->kinds);

        return $category === null
            ? $all
            : array_values(array_filter($all, static fn (NodeKind $k) => $k->category === $category));
    }

    /**
     * Build a fresh config for a new node of this kind: the kind's
     * `defaultConfig` plus any per-field defaults not already set. Faithful to
     * `defaultConfigFor` — a present key (even null) is left untouched.
     *
     * @return array<string,mixed>
     */
    public function defaultConfigFor(NodeKind $kind): array
    {
        $config = $kind->defaultConfig;
        foreach ($kind->configSchema as $field) {
            if (array_key_exists($field->key, $config)) {
                continue;
            }
            if ($field->hasDefault()) {
                $config[$field->key] = $field->default;
            }
        }

        return $config;
    }

    /**
     * Light validation of a config against a kind's schema — required-field and
     * type checks, faithful to `validateConfig`. Returns a list of issues
     * (empty = valid).
     *
     * @param array<string,mixed> $config
     * @return list<array{key:string,message:string}>
     */
    public function validateConfig(NodeKind $kind, array $config): array
    {
        $issues = [];
        foreach ($kind->configSchema as $field) {
            $value = $config[$field->key] ?? null;

            if ($field->required && ($value === null || $value === '')) {
                $issues[] = ['key' => $field->key, 'message' => "{$field->label} is required"];

                continue;
            }
            if ($value === null) {
                continue;
            }
            $message = $this->validateField($field, $value);
            if ($message !== null) {
                $issues[] = ['key' => $field->key, 'message' => $message];
            }
        }

        return $issues;
    }

    private function validateField(ConfigField $field, mixed $value): ?string
    {
        switch ($field->type) {
            case 'text':
            case 'textarea':
            case 'expression':
            case 'credential':
                return is_string($value) ? null : "{$field->label} must be a string";
            case 'number':
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                    return "{$field->label} must be a number";
                }
                if ($field->min !== null && $value < $field->min) {
                    return "{$field->label} must be >= {$field->min}";
                }
                if ($field->max !== null && $value > $field->max) {
                    return "{$field->label} must be <= {$field->max}";
                }

                return null;
            case 'switch':
                return is_bool($value) ? null : "{$field->label} must be a boolean";
            case 'select':
                $allowed = array_map(static fn (array $o) => $o['value'], $field->options);

                return in_array((string) $value, $allowed, true)
                    ? null
                    : "{$field->label} must be one of ".implode(', ', $allowed);
            case 'json':
                return null; // permissive — any JSON-shaped value passes.
            default:
                return null;
        }
    }

    /** Default header accent per category. Faithful to `categoryAccent`. */
    public static function categoryAccent(string $category): string
    {
        return match ($category) {
            'trigger' => '#10b981',
            'logic' => '#f59e0b',
            'data' => '#0ea5e9',
            'ai' => '#8b5cf6',
            'io' => '#3b82f6',
            'human' => '#ec4899',
            'output' => '#a855f7',
            default => '#71717a',
        };
    }
}
