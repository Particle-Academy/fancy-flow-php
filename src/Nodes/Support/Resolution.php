<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * What a resolution attempt ANSWERS — did the path resolve, and to what.
 *
 * `Expr::resolvePath()` cannot express this, and that is the defect it exists
 * to fix: it returns `null` both for "this path does not exist" and for "this
 * path exists and holds null". One value standing for two states.
 *
 * A second return channel rather than a cleverer sentinel, because **every
 * sentinel is a legal value for somebody** — `''`, `null` and `false` are all
 * things a real workflow payload carries.
 */
final readonly class Resolution
{
    private function __construct(
        /** Whether the path resolved at all. `false` means it does not exist. */
        public bool $resolved,
        /** The value, when `resolved`. `null` when not — do not read it blind. */
        public mixed $value,
    ) {}

    public static function found(mixed $value): self
    {
        return new self(true, $value);
    }

    public static function missing(): self
    {
        return new self(false, null);
    }
}
