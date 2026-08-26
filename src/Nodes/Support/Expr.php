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
    /**
     * Evaluate a template against a context.
     *
     * `$onUnresolved` decides what happens to a path that does not resolve.
     * It defaults to {@see UnresolvedPolicy::Empty} — today's behaviour,
     * unchanged — because this is opt-in before it is default, at the request
     * of the consumer who reported the problem.
     */
    public static function evaluate(
        mixed $template,
        array $context,
        UnresolvedPolicy $onUnresolved = UnresolvedPolicy::Empty,
    ): mixed {
        if (! is_string($template)) {
            return $template;
        }

        $trimmed = trim($template);

        // Whole-string single expression → return the raw resolved value.
        if (preg_match('/^\{\{\s*(.*?)\s*\}\}$/s', $trimmed, $m) === 1) {
            $r = self::tryResolvePath($m[1], $context);
            if ($r->resolved) {
                return $r->value;
            }

            // This branch returns `null` under Empty, not `''` -- that is what
            // it has always done, and the asymmetry is deliberate: this branch
            // preserves TYPE, so its absent value is the typed one.
            return match ($onUnresolved) {
                UnresolvedPolicy::Throw => throw new UnresolvedPathException($m[1]),
                UnresolvedPolicy::Keep => $template,
                UnresolvedPolicy::Empty => null,
            };
        }

        // Otherwise interpolate each {{ … }} as a string.
        return preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/s',
            static function (array $m) use ($context, $onUnresolved): string {
                $r = self::tryResolvePath($m[1], $context);
                if ($r->resolved) {
                    return self::stringify($r->value);
                }

                return match ($onUnresolved) {
                    UnresolvedPolicy::Throw => throw new UnresolvedPathException($m[1]),
                    // `$m[0]` is the WHOLE match, so the original spacing comes
                    // back byte-identical. A "kept" template that returned
                    // subtly reformatted would be its own small lie.
                    UnresolvedPolicy::Keep => $m[0],
                    UnresolvedPolicy::Empty => '',
                };
            },
            $template,
        );
    }

    /**
     * Resolve a dot-path, reporting WHETHER it resolved.
     *
     * The same walk as {@see self::resolvePath()} — deliberately, since that
     * method is now defined in terms of this one. Two copies of a traversal
     * agree right up until someone edits one of them, and nothing anywhere
     * reports that.
     */
    public static function tryResolvePath(string $path, array $context): Resolution
    {
        $path = trim($path);
        if ($path === '') {
            return Resolution::missing();
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
            } elseif (is_object($cursor) && property_exists($cursor, $segment)) {
                // A DECLARED property holding null. `isset()` is false for it --
                // `isset()` is the same absent-vs-null collapse this method
                // exists to remove, so relying on it alone would reproduce the
                // bug inside the fix.
                //
                // Checked AFTER isset() rather than instead of it, because
                // `property_exists()` does not consult `__isset`/`__get`: an
                // Eloquent model's attributes are magic, so `property_exists`
                // is false for every one of them while `isset` is true. Testing
                // isset first keeps magic objects working exactly as before and
                // only adds the null-valued declared-property case.
                $cursor = $cursor->{$segment};
            } else {
                return Resolution::missing();
            }
        }

        return Resolution::found($cursor);
    }

    /**
     * Resolve a dot-path against the context, honoring the `$json` / `$input` alias.
     *
     * Returns `null` both for "did not resolve" and for "resolved to null" —
     * use {@see self::tryResolvePath()} when you need to tell those apart.
     */
    public static function resolvePath(string $path, array $context): mixed
    {
        return self::tryResolvePath($path, $context)->value;
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
