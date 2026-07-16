<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\LlmClient;
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
        $options = [
            'provider' => $ctx->option('provider', 'anthropic'),
            'model' => $ctx->option('model'),
            'system' => $ctx->option('system'),
            'temperature' => $ctx->option('temperature'),
            'max_tokens' => $ctx->option('max_tokens'),
            'tools' => $ctx->option('tools'),
        ];

        $ctx->emit(RunEvent::log('info', 'llm_call → '.(string) $ctx->option('model', 'model'), $ctx->node->id));

        return $this->llm->complete($prompt, array_filter($options, static fn ($v) => $v !== null));
    }
}
