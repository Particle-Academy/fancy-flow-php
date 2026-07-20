<?php

declare(strict_types=1);

namespace FancyFlow\Registry;

/**
 * The naming convention for node-kind ids, and the only place it is spelled out.
 *
 * A kind's `name` is its CANONICAL id and is what gets written into saved
 * documents — so a bare name two packages could both claim is unfixable after
 * the fact: the ambiguous string is already in the document. Canonical ids are
 * therefore namespaced (`@particle-academy/llm_router`), and every previous
 * spelling stays registered as an ALIAS so graphs saved before the rename keep
 * opening. Parity with fancy-flow 0.11.0.
 *
 * {@see variants()} is the structural fallback for lookups that have no
 * registry to consult (see {@see \FancyFlow\ExecutorRegistry}) — explicit
 * aliases always take precedence over convention.
 */
final class KindId
{
    public const NAMESPACE = '@particle-academy/';

    /** The namespace shipped before the package name was settled. */
    public const LEGACY_NAMESPACE = '@fancy/';

    /** `manual_trigger` → `@particle-academy/manual_trigger`. Already-namespaced ids pass through. */
    public static function canonical(string $name): string
    {
        return self::isNamespaced($name) ? $name : self::NAMESPACE.$name;
    }

    /** `@particle-academy/manual_trigger` → `manual_trigger`. */
    public static function bare(string $id): string
    {
        $slash = strrpos($id, '/');

        return $slash === false || ! self::isNamespaced($id) ? $id : substr($id, $slash + 1);
    }

    public static function isNamespaced(string $id): bool
    {
        return str_starts_with($id, '@');
    }

    /**
     * The aliases a built-in kind keeps: its bare name and the legacy namespace.
     *
     * @return list<string>
     */
    public static function builtinAliases(string $name): array
    {
        $bare = self::bare($name);

        return [$bare, self::LEGACY_NAMESPACE.$bare];
    }

    /**
     * Does `$id` name the built-in kind `$bareName` under any of its spellings?
     *
     * Deliberately narrow: only the bare name and fancy-flow's own namespaces
     * match, so a third party's `@acme/note` is NOT mistaken for the built-in.
     */
    public static function matches(string $id, string $bareName): bool
    {
        return $id === $bareName
            || $id === self::NAMESPACE.$bareName
            || $id === self::LEGACY_NAMESPACE.$bareName;
    }

    /**
     * Every id this one could also be written as, `$id` first.
     *
     * Order is preference order: an exact match wins, then the canonical form,
     * then the older spellings.
     *
     * @return list<string>
     */
    public static function variants(string $id): array
    {
        $bare = self::bare($id);
        $variants = [$id, self::NAMESPACE.$bare, self::LEGACY_NAMESPACE.$bare, $bare];

        return array_values(array_unique($variants));
    }
}
