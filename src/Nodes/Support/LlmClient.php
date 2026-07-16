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
     * @param array<string,mixed> $options provider, model, system, temperature, max_tokens, tools, …
     * @return array{text:string,usage?:array<string,mixed>,raw?:mixed}
     */
    public function complete(string $prompt, array $options = []): array;
}
