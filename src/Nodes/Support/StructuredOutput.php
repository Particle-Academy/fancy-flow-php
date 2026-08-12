<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

use FancyFlow\Exceptions\FlowException;

/**
 * Turning a model's reply into schema-typed data.
 *
 * A host adapter that supports provider-native structured output (Anthropic
 * tool results, OpenAI `response_format: json_schema`) should return the parsed
 * value itself, and then none of this runs. This exists for the rest: an
 * adapter that ignores `response_schema` and returns prose would otherwise hand
 * downstream nodes a string where they expect data, silently.
 *
 * That is the failure this class refuses to allow. **Every path here either
 * produces schema-valid data or throws.** There is deliberately no "return null
 * and let the next node deal with it" — a truncated array that decodes to
 * nothing looks exactly like a model that found no results, and a workflow that
 * silently processes zero records is the expensive kind of wrong.
 *
 * ## The validator is a SUBSET, and saying so is the point
 *
 * Enforced: `type`, `required`, `properties` (recursively), `items`
 * (recursively), `enum`. Everything else in a schema — `minLength`, `pattern`,
 * `format`, `additionalProperties`, `oneOf` — is **ignored, not enforced**.
 *
 * The subset is what a workflow author actually leans on to keep a downstream
 * `{{ $json.data[0].title }}` from resolving to nothing, and it is
 * dependency-free, which matters more here than completeness: core takes no
 * runtime dependencies, and a half-integrated validator that silently skips the
 * keyword you relied on is worse than one that names what it checks.
 */
final class StructuredOutput
{
    /**
     * Pull a JSON value out of whatever the model actually said.
     *
     * Handles the three things models reliably do despite instructions, each of
     * which was reported from real runs:
     *
     *  - wrap the JSON in a ```json fence
     *  - open with a prose preamble ("Here are the results:")
     *  - truncate mid-array on a long answer
     *
     * The first two are recoverable and are recovered. The third is NOT: a
     * truncated array is indistinguishable from a short one once it fails to
     * parse, so it throws rather than guessing.
     */
    public static function extract(string $text): mixed
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw new FlowException('The model returned an empty response, so there is no JSON to read.');
        }

        // The happy path: the whole reply is the value.
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_scalar($decoded))) {
            return $decoded;
        }

        // A fenced block, ```json or bare ```.
        if (preg_match('/```(?:json)?\s*\R(.*?)\R?\s*```/s', $trimmed, $m) === 1) {
            $inner = trim($m[1]);
            $decoded = json_decode($inner, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            throw new FlowException(
                'The model returned a fenced block that is not valid JSON: '.json_last_error_msg().
                '. This is usually truncation — raise max_tokens, or narrow the schema so the answer fits.'
            );
        }

        // A preamble, a trailing note, or both: take the first balanced value.
        $slice = self::firstBalancedValue($trimmed);
        if ($slice !== null) {
            $decoded = json_decode($slice, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        throw new FlowException(
            'The model did not return JSON that could be parsed. First 200 characters: '.
            mb_substr($trimmed, 0, 200)
        );
    }

    /**
     * The first balanced `{...}` or `[...]` in a string.
     *
     * Scanned rather than matched with a regex. Balanced delimiters are not a
     * regular language, so a regex either gets it wrong on nesting or becomes
     * the kind of backtracking pattern that has already cost this suite three
     * ReDoS alerts. This is a single left-to-right pass, and it tracks string
     * state so a brace inside `"{"` does not count.
     */
    private static function firstBalancedValue(string $text): ?string
    {
        $length = strlen($text);
        $start = null;
        $opener = '';
        $closer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($start === null) {
                if ($char === '{' || $char === '[') {
                    $start = $i;
                    $opener = $char;
                    $closer = $char === '{' ? '}' : ']';
                    $depth = 1;
                }

                continue;
            }

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\' && $inString) {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === $opener) {
                $depth++;
            } elseif ($char === $closer) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        // Opened and never closed — truncation. Returning the partial text
        // would decode to null and read as "no results".
        return null;
    }

    /**
     * Validate against the supported subset. Returns human-readable problems,
     * empty when the value conforms.
     *
     * @param  array<string,mixed>  $schema
     * @return list<string>
     */
    public static function validate(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];

        $type = $schema['type'] ?? null;
        if (is_string($type) && ! self::matchesType($value, $type)) {
            return [$path.' should be '.$type.', got '.self::describe($value)];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' is not one of the allowed values';
        }

        if (is_array($value) && self::isAssoc($value, $type)) {
            foreach ((array) ($schema['required'] ?? []) as $key) {
                if (! array_key_exists((string) $key, $value)) {
                    $errors[] = $path.'.'.$key.' is required but missing';
                }
            }

            $properties = $schema['properties'] ?? null;
            if (is_array($properties)) {
                foreach ($properties as $key => $subSchema) {
                    if (is_array($subSchema) && array_key_exists($key, $value)) {
                        $errors = array_merge(
                            $errors,
                            self::validate($value[$key], $subSchema, $path.'.'.$key)
                        );
                    }
                }
            }
        }

        if (is_array($value) && $type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            foreach (array_values($value) as $index => $item) {
                $errors = array_merge(
                    $errors,
                    self::validate($item, $schema['items'], $path.'['.$index.']')
                );
            }
        }

        return array_values($errors);
    }

    /** @param array<mixed> $value */
    private static function isAssoc(array $value, mixed $type): bool
    {
        if ($type === 'object') {
            return true;
        }

        if ($type === 'array') {
            return false;
        }

        return $value === [] ? false : array_keys($value) !== range(0, count($value) - 1);
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || array_keys($value) !== range(0, count($value) - 1)),
            'array' => is_array($value) && ($value === [] || array_keys($value) === range(0, count($value) - 1)),
            'string' => is_string($value),
            // JSON has one number type; a schema saying `number` must accept an
            // int, or `{"type":"number"}` rejects `3` and every author hits it.
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] || array_keys($value) === range(0, count($value) - 1) ? 'array' : 'object';
        }

        return get_debug_type($value);
    }
}
