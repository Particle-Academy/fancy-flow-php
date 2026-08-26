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
     * ## `tool_calls` — the key the agent node reads, and the contract did not declare
     *
     * `AgentExecutor` runs a tool loop: it calls the model, and if the reply
     * carries `tool_calls` it invokes each one and calls again. That key was
     * absent from this contract until 0.42.0, so an implementation written to
     * the contract returned none and **the loop could never engage** — an
     * `agent` node degraded silently to a single completion, which looks exactly
     * like a model that chose not to use a tool.
     *
     * The shipped `EchoLlmClient` returns none, so nothing in this package
     * exercised the loop either. Undeclared field, read as if declared — the
     * same shape as `outputShape` before it existed, one layer down.
     *
     * An adapter that CAN emit tool calls should, and should cap the provider at
     * ONE step: `AgentExecutor` owns the loop. Letting the provider run its own
     * loop as well invokes every tool twice and hides half the trace from the
     * host's audit.
     *
     * Each entry: `name` (the tool to invoke) and `arguments` (its arguments).
     * `id` is carried through when the provider supplies one, so a reply can be
     * correlated back.
     *
     * Reported by the Prism harness while reviewing v0.41.0 for integration.
     *
     * @param array<string,mixed> $options provider, model, system, temperature, max_tokens, tools, response_schema, …
     * @return array{
     *     text: string,
     *     data?: mixed,
     *     usage?: array<string,mixed>,
     *     raw?: mixed,
     *     tool_calls?: list<array{name:string, arguments?:array<string,mixed>, id?:string}>,
     * }
     */
    public function complete(string $prompt, array $options = []): array;
}
