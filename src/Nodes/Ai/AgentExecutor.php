<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\LlmClient;
use FancyFlow\Nodes\Support\ToolInvoker;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `agent` — an LLM agent with tools and bounded multi-step reasoning. Backed by
 * the {@see LlmClient} and {@see ToolInvoker} contracts, so it is framework-free
 * (the Laravel layer binds laravel/ai + your registered tools). Each step is
 * streamed via `emit()` for live status.
 *
 * The loop: call the model; if it returns `tool_calls`, invoke each tool and
 * feed the results back; repeat up to `max_steps`; return the final text + the
 * full step trace (an auditable agent run).
 */
final class AgentExecutor implements NodeExecutor
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly ToolInvoker $tools,
    ) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $options = array_filter([
            'model' => $ctx->option('model'),
            'system' => $ctx->option('system'),
            'tools' => $ctx->option('tools'),
            'temperature' => $ctx->option('temperature'),
        ], static fn ($v) => $v !== null);

        $maxSteps = max(1, (int) $ctx->option('max_steps', 3));
        $prompt = Expr::text(Expr::evaluate($ctx->option('prompt', ''), $ctx->inputs));

        $steps = [];
        $response = ['text' => ''];
        for ($step = 1; $step <= $maxSteps; $step++) {
            $response = $this->llm->complete($prompt, $options);
            $steps[] = ['prompt' => $prompt, 'response' => $response];
            $ctx->emit(RunEvent::log('info', "agent step {$step}", $ctx->node->id));

            $calls = $response['tool_calls'] ?? null;
            if (! is_array($calls) || $calls === []) {
                return ['text' => (string) ($response['text'] ?? ''), 'steps' => $steps];
            }

            $results = [];
            foreach ($calls as $call) {
                $name = (string) ($call['name'] ?? $call['tool'] ?? '');
                $args = (array) ($call['args'] ?? $call['arguments'] ?? []);
                $results[] = ['tool' => $name, 'result' => $this->tools->invoke($name, $args)];
            }
            $prompt = 'Tool results: '.json_encode($results, JSON_UNESCAPED_SLASHES);
        }

        return ['text' => (string) ($response['text'] ?? ''), 'steps' => $steps, 'truncated' => true];
    }
}
