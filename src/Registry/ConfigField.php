<?php

declare(strict_types=1);

namespace FancyFlow\Registry;

/**
 * One field in a kind's config schema — the declarative form spec shared with
 * the editor. The PHP twin of fancy-flow's `ConfigField` tagged union,
 * collapsed to a single class carrying every variant's attributes.
 *
 * `type` is one of: text, textarea, number, select, switch, json, expression,
 * credential. Unknown attributes for a given type are simply null/empty.
 */
final class ConfigField
{
    /** Sentinel distinguishing "no default declared" from an explicit null. */
    private const UNSET = "\0__ff_unset__\0";

    /**
     * @param list<array{value:string,label:string}> $options
     */
    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly mixed $default = self::UNSET,
        public readonly ?string $description = null,
        public readonly array $options = [],
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly ?float $step = null,
        public readonly ?string $placeholder = null,
        public readonly ?string $example = null,
        public readonly ?string $credentialType = null,
        public readonly ?int $rows = null,
        public readonly ?string $language = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            type: (string) ($raw['type'] ?? 'text'),
            key: (string) $raw['key'],
            label: (string) ($raw['label'] ?? $raw['key']),
            required: (bool) ($raw['required'] ?? false),
            default: array_key_exists('default', $raw) ? $raw['default'] : self::UNSET,
            description: isset($raw['description']) ? (string) $raw['description'] : null,
            options: self::normalizeOptions($raw['options'] ?? []),
            min: isset($raw['min']) ? (float) $raw['min'] : null,
            max: isset($raw['max']) ? (float) $raw['max'] : null,
            step: isset($raw['step']) ? (float) $raw['step'] : null,
            placeholder: isset($raw['placeholder']) ? (string) $raw['placeholder'] : null,
            example: isset($raw['example']) ? (string) $raw['example'] : null,
            credentialType: isset($raw['credentialType']) ? (string) $raw['credentialType'] : null,
            rows: isset($raw['rows']) ? (int) $raw['rows'] : null,
            language: isset($raw['language']) ? (string) $raw['language'] : null,
        );
    }

    public function hasDefault(): bool
    {
        return $this->default !== self::UNSET;
    }

    public function default(): mixed
    {
        return $this->hasDefault() ? $this->default : null;
    }

    /** @param mixed $options @return list<array{value:string,label:string}> */
    private static function normalizeOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }
        $out = [];
        foreach ($options as $opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $out[] = ['value' => (string) $opt['value'], 'label' => (string) ($opt['label'] ?? $opt['value'])];
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $out = ['type' => $this->type, 'key' => $this->key, 'label' => $this->label];
        if ($this->required) {
            $out['required'] = true;
        }
        if ($this->hasDefault()) {
            $out['default'] = $this->default;
        }
        foreach ([
            'description' => $this->description,
            'min' => $this->min,
            'max' => $this->max,
            'step' => $this->step,
            'placeholder' => $this->placeholder,
            'example' => $this->example,
            'credentialType' => $this->credentialType,
            'rows' => $this->rows,
            'language' => $this->language,
        ] as $k => $v) {
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        if ($this->options !== []) {
            $out['options'] = $this->options;
        }

        return $out;
    }
}
