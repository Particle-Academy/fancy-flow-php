<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * The model backend the llm_call executor uses. The default {@see EchoLlmClient}
 * returns a deterministic canned completion; the Laravel layer binds `laravel/ai`
 * or Prism.
 */
interface LlmClient
{
    /**
     * Complete a prompt.
     *
     * `$options['response_schema']` is a JSON Schema the caller wants the
     * result to satisfy. An adapter that can constrain the model natively
     * (Anthropic tool result, OpenAI `response_format: json_schema`) SHOULD do
     * so and return the parsed value as `data`. One that cannot may ignore it
     * and return text as usual — `llm_call` extracts and validates from `text`
     * in that case, so the node's contract holds either way.
     *
     * Returning `data` that does not satisfy the schema is an error the node
     * raises: it is validated, not trusted.
     *
     * @param array<string,mixed> $options provider, model, system, temperature, max_tokens, tools, response_schema, …
     * @return array{text:string,data?:mixed,usage?:array<string,mixed>,raw?:mixed}
     */
    public function complete(string $prompt, array $options = []): array;
}
