<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Logic;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;

/**
 * `transform` — reshape in place. One `out` port, always active.
 *
 * A port of `transformExecutor` in `@particle-academy/fancy-flow`, the
 * reference for this kind; which output it produces is {@see self::path()}.
 * Until 0.65.0 this read `expression` alone, so fields mode — the editor's
 * DEFAULT — did not exist here and a graph built field by field passed its
 * input straight through. Values resolve through {@see Expr}. Hosts wanting a
 * full expression language (e.g. symfony/expression-language) override this
 * executor.
 */
final class TransformExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $config = $ctx->node->config;

        return match (self::path($config)) {
            'expression' => Expr::evaluate($config['expression'], $ctx->inputs),
            'fields' => self::build($config, $ctx->inputs),
            default => $ctx->input('in', $ctx->inputs),
        };
    }

    /**
     * Which of its three outputs a `transform` config produces. The executor
     * AND the kind's `emits` read this, as in the reference.
     *
     * - `expression` — `mode` is `expression` or unset, and `expression` is a
     *   non-blank string.
     * - `fields` — otherwise, when at least one row has a non-blank `key`.
     * - `input` — the input, untouched: an explicit `mode: "expression"` with no
     *   expression, or no row with a key (the editor's untouched default).
     *
     * Any other `mode` value, including one the schema does not list, takes the
     * fields path, as TypeScript's does; the validator is what reports it.
     *
     * @param array<string,mixed> $config
     */
    public static function path(array $config): string
    {
        $mode = $config['mode'] ?? null;
        if ($mode === null || $mode === 'expression') {
            $expression = $config['expression'] ?? null;
            if (is_string($expression) && self::trim($expression) !== '') {
                return 'expression';
            }
            if ($mode === 'expression') {
                return 'input';
            }
        }

        return self::rows($config) !== [] ? 'fields' : 'input';
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $inputs
     * @return array<string,mixed>
     */
    private static function build(array $config, array $inputs): array
    {
        $out = [];
        foreach (self::rows($config) as [$key, $value]) {
            $out[$key] = Expr::evaluate($value, $inputs);
        }

        return $out;
    }

    /**
     * The rows fields mode writes, in order, each key trimmed. A row that is not
     * an object, or whose `key` is not a non-blank string, is skipped. A later
     * row with the same key overwrites the value and keeps the first position,
     * which is what a JavaScript object does.
     *
     * @param array<string,mixed> $config
     * @return list<array{0:string,1:mixed}>
     */
    private static function rows(array $config): array
    {
        $rows = $config['fields'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row !== [] && array_is_list($row))) {
                continue;
            }
            $key = $row['key'] ?? null;
            $trimmed = is_string($key) ? self::trim($key) : '';
            if ($trimmed !== '') {
                $out[] = [$trimmed, $row['value'] ?? null];
            }
        }

        return $out;
    }

    /**
     * JavaScript's `trim()`: whitespace and line terminators, Unicode-aware --
     * PCRE `\s` under `/u` (Unicode white space), plus U+FEFF and U+00A0.
     *
     * Public because it is the ONE definition of a blank key: the validator
     * ({@see \FancyFlow\NodeKindRegistry}) judges a `skipBlankRows` row's text
     * cells with it, so a key this executor skips is a key admission refuses
     * whenever the row carries a value. They used to disagree (0.65.1).
     */
    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{A0}]+|[\s\x{FEFF}\x{A0}]+$/u', '', $value);
    }
}
