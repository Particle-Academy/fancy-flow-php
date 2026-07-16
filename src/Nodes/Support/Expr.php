<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * A tiny, safe expression resolver for the batteries-included executors. It is
 * deliberately NOT a general expression language — it resolves `{{ path }}`
 * references against a context, with no arbitrary code evaluation. Hosts that
 * want full expressions override the executor (e.g. symfony/expression-language).
 *
 *   - `{{ $json.user.name }}`  → dot-path into the context (aliased below).
 *   - `{{ answer }}`           → top-level key.
 *   - A string that is exactly one `{{ … }}` returns the resolved *value*
 *     (any type). Otherwise every `{{ … }}` is stringified and interpolated.
 *   - Non-string templates are returned unchanged.
 *
 * `$json` (and `$input`) alias the primary input — the `in` port value when
 * present, otherwise the whole context.
 */
final class Expr
{
    public static function evaluate(mixed $template, array $context): mixed
    {
        if (! is_string($template)) {
            return $template;
        }

        $trimmed = trim($template);

        // Whole-string single expression → return the raw resolved value.
        if (preg_match('/^\{\{\s*(.*?)\s*\}\}$/s', $trimmed, $m) === 1) {
            return self::resolvePath($m[1], $context);
        }

        // Otherwise interpolate each {{ … }} as a string.
        return preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            static fn (array $m): string => self::stringify(self::resolvePath($m[1], $context)),
            $template,
        );
    }

    /** Resolve a dot-path against the context, honoring the `$json` / `$input` alias. */
    public static function resolvePath(string $path, array $context): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $head = $segments[0];

        if ($head === '$json' || $head === '$input') {
            $cursor = array_key_exists('in', $context) ? $context['in'] : $context;
            array_shift($segments);
        } else {
            $cursor = $context;
        }

        foreach ($segments as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
            } elseif (is_object($cursor) && isset($cursor->{$segment})) {
                $cursor = $cursor->{$segment};
            } else {
                return null;
            }
        }

        return $cursor;
    }

    /**
     * Truthiness used by the branch / switch executors. Strings like "false",
     * "0", "no", "off", and "" are false; empty arrays and null are false;
     * numbers use `!= 0`. Everything else follows PHP truthiness.
     */
    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off', 'null'], true);
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return (bool) $value;
    }

    /** Coerce any value to a string the way template interpolation does. */
    public static function text(mixed $value): string
    {
        return self::stringify($value);
    }

    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
