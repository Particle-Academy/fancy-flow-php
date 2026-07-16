<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * A deterministic {@see LlmClient} — echoes the prompt as the completion so
 * agentic flows run offline in tests and local dev. Records prompts in {@see $prompts}.
 */
final class EchoLlmClient implements LlmClient
{
    /** @var list<array{prompt:string,options:array<string,mixed>}> */
    public array $prompts = [];

    public function complete(string $prompt, array $options = []): array
    {
        $this->prompts[] = ['prompt' => $prompt, 'options' => $options];
        $model = (string) ($options['model'] ?? 'echo');

        return [
            'text' => "[{$model}] {$prompt}",
            'usage' => ['input_tokens' => str_word_count($prompt), 'output_tokens' => str_word_count($prompt)],
        ];
    }
}
