<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Exceptions\FlowException;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\LlmClient;
use FancyFlow\Nodes\Support\StructuredOutput;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `llm_call` — send a prompt (+ system + params) to an {@see LlmClient} and
 * return its completion. The prompt is resolved through {@see Expr} against the
 * node's inputs. The framework-free default uses a deterministic echo client;
 * the Laravel layer binds laravel/ai or Prism.
 */
final class LlmCallExecutor implements NodeExecutor
{
    public function __construct(private readonly LlmClient $llm) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $prompt = Expr::text(Expr::evaluate($ctx->option('prompt', ''), $ctx->inputs));
        $schema = self::schema($ctx->option('response_schema'));

        $options = [
            'provider' => $ctx->option('provider', 'anthropic'),
            'model' => $ctx->option('model'),
            'system' => $ctx->option('system'),
            'temperature' => $ctx->option('temperature'),
            'max_tokens' => $ctx->option('max_tokens'),
            'tools' => $ctx->option('tools'),
            // Carried to the adapter so a client that supports provider-native
            // structured output can constrain the model instead of hoping the
            // prompt wording holds.
            'response_schema' => $schema,
        ];

        $ctx->emit(RunEvent::log('info', 'llm_call → '.(string) $ctx->option('model', 'model'), $ctx->node->id));

        $result = $this->llm->complete($prompt, array_filter($options, static fn ($v) => $v !== null));

        if ($schema === null) {
            return $result;
        }

        return $this->structured($result, $schema, $ctx);
    }

    /**
     * Attach `data` — the parsed, schema-checked value.
     *
     * An adapter using provider-native structured output should have returned
     * `data` already; that value is still validated rather than trusted,
     * because "the provider promised" is not the same as "the provider did",
     * and the whole point of asking for a schema is that the next node can rely
     * on the shape.
     *
     * When there is no `data`, the text is parsed. That is the case for every
     * adapter that ignores `response_schema` — which, without this, would hand
     * a downstream `{{ $json.data.title }}` an undefined and report nothing.
     *
     * @param  array{text?:string,data?:mixed,usage?:array<string,mixed>,raw?:mixed}  $result
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function structured(array $result, array $schema, ExecutionContext $ctx): array
    {
        $data = array_key_exists('data', $result)
            ? $result['data']
            : StructuredOutput::extract((string) ($result['text'] ?? ''));

        $errors = StructuredOutput::validate($data, $schema);

        if ($errors !== []) {
            // Loudly, with the reasons. A schema-invalid result that flows on
            // as `null` is the silent-empty-parse this feature exists to end.
            throw new FlowException(
                'The model\'s response did not match the requested schema: '.implode('; ', $errors)
            );
        }

        $ctx->emit(RunEvent::log('info', 'llm_call → schema-valid data', $ctx->node->id));

        return $result + ['data' => $data];
    }

    /**
     * Accept a schema as an array or as a JSON string.
     *
     * The editor's `json` field can hand either across, depending on whether
     * the host stored the parsed value or the raw text the author typed.
     * Accepting one and silently ignoring the other would make the feature work
     * on one host and do nothing on another.
     *
     * @return array<string,mixed>|null
     */
    private static function schema(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw === [] ? null : $raw;
        }

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw new FlowException('`response_schema` is not valid JSON, so the model cannot be constrained by it.');
            }

            return $decoded;
        }

        return null;
    }
}
