<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * Resolving what a caller passed against what a workflow declared.
 *
 * The PHP twin of `@particle-academy/fancy-flow`'s
 * `src/runtime/workflow-props.ts`. Deliberately a pure function over two plain
 * arrays rather than a step inside the runner: `suites/flow/workflow-props` in
 * `particle-academy/fancy-conformance` is the table all three runtimes run, and
 * a rule that lives inside a runner is a rule each runtime re-derives.
 *
 * ## Every branch exists to make a mistake LOUD
 *
 * The behaviour being replaced was silence. Run inputs were keyed BY NODE ID,
 * so a caller had to know the trigger happened to be called `t`; and nothing
 * declared what a workflow accepted, so a misspelled key was not an error — the
 * value sat unread, the node saw nothing, and the run reported success with
 * output that was quietly wrong.
 *
 * So an unknown key fails, a missing required value fails, and a wrong type
 * fails. None of them is a warning: a warning on a queue worker is a line in a
 * log nobody opens.
 *
 * ## The CODE is the contract, not the message
 *
 * Each runtime words its errors idiomatically. The shared table asserts
 * `code`, so parity is pinned on the decision rather than on the phrasing —
 * otherwise three implementations would be held to a translation, and a
 * wording improvement would go red having changed nothing.
 */
final class WorkflowProps
{
    public const UNKNOWN_INPUT = 'unknown_input';

    public const MISSING_REQUIRED = 'missing_required';

    public const TYPE_MISMATCH = 'type_mismatch';

    /**
     * Check and fill a caller's props.
     *
     * @param  list<array<string,mixed>>|null  $declared  the workflow's `inputs`
     * @param  array<string,mixed>|null  $passed  what the caller supplied
     * @return array{ok:true,props:array<string,mixed>}|array{ok:false,code:string,error:string}
     */
    public static function resolve(?array $declared, ?array $passed): array
    {
        $inputs = $declared ?? [];
        $given = $passed ?? [];

        $names = [];
        foreach ($inputs as $input) {
            if (is_array($input) && is_string($input['name'] ?? null)) {
                $names[$input['name']] = true;
            }
        }

        // UNKNOWN KEYS FIRST, and this is the check the whole feature is for.
        //
        // A caller who misspells `topic` as `topik` has configured nothing, and
        // before this the run went ahead and looked fine. Checking it before
        // anything else means the error names the word they TYPED rather than
        // complaining that a key they believe they supplied is missing.
        foreach (array_keys($given) as $name) {
            if (! isset($names[$name])) {
                $known = array_keys($names);
                $suffix = $known === []
                    ? 'this workflow declares no inputs'
                    : 'known inputs: '.implode(', ', $known);

                return [
                    'ok' => false,
                    'code' => self::UNKNOWN_INPUT,
                    'error' => sprintf('Unknown workflow input "%s" — %s.', $name, $suffix),
                ];
            }
        }

        $resolved = [];

        foreach ($inputs as $input) {
            if (! is_array($input) || ! is_string($input['name'] ?? null)) {
                continue;
            }

            $name = $input['name'];
            $type = is_string($input['type'] ?? null) ? $input['type'] : null;

            // `array_key_exists`, never `isset` and never a truthiness check.
            // `isset` is false for a supplied NULL, and `0` / `false` / `''`
            // are values a caller MEANT to pass — a default applied over them
            // is a silent override, and a declared limit of 0 quietly becoming
            // 10 is not an error anybody observes.
            $supplied = array_key_exists($name, $given);
            $hasDefault = array_key_exists('default', $input);

            if (! $supplied) {
                if ($hasDefault) {
                    $resolved[$name] = $input['default'];

                    continue;
                }

                if (($input['required'] ?? false) === true) {
                    return [
                        'ok' => false,
                        'code' => self::MISSING_REQUIRED,
                        'error' => sprintf(
                            'Missing required workflow input "%s"%s.',
                            $name,
                            $type === null ? '' : " ({$type})",
                        ),
                    ];
                }

                // Absent stays ABSENT — not null, not ''. PHP has one absent
                // value and JavaScript has two; writing a placeholder here
                // would make `{{ $props.note }}` resolve differently on the two
                // runtimes for the same graph.
                continue;
            }

            $value = $given[$name];

            // An undeclared type accepts anything. "I am not asserting a shape"
            // must not degrade into "nothing is allowed", which is how a
            // defensively-written validator rejects valid calls.
            if ($type !== null) {
                $actual = self::typeOf($value);
                if ($actual !== $type) {
                    return [
                        'ok' => false,
                        'code' => self::TYPE_MISMATCH,
                        'error' => sprintf(
                            'Workflow input "%s" expects %s, got %s.',
                            $name,
                            $type,
                            $actual,
                        ),
                    ];
                }
            }

            $resolved[$name] = $value;
        }

        return ['ok' => true, 'props' => $resolved];
    }

    /**
     * The runtime type of a value, in the vocabulary a declaration uses.
     *
     * PHP has ONE array type and the JSON that crosses between runtimes has
     * two, so the split is by shape: a list is an `array`, a string-keyed map
     * is an `object`. `array_is_list` is the same question JSON encoding asks,
     * which is what keeps this agreeing with the TypeScript side — there,
     * `typeof []` is `"object"` and the check has to special-case arrays for
     * the mirror-image reason.
     *
     * An empty PHP array is a LIST by `array_is_list`, so it reads as `array`.
     * That matches `json_encode([])` producing `[]`, and a graph round-tripped
     * through JSON is the only way the two runtimes ever see the same value.
     */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => get_debug_type($value),
        };
    }
}
