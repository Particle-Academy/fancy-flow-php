<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Io;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\HttpClient;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `webhook_out` — POST a payload to a configured URL via an {@see HttpClient}.
 * The URL and payload are resolved through {@see Expr} against the node's inputs.
 */
final class WebhookOutExecutor implements NodeExecutor
{
    public function __construct(private readonly HttpClient $http) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $url = Expr::text(Expr::evaluate($ctx->option('url', ''), $ctx->inputs));
        $headers = $ctx->option('headers', []);
        $headers = is_array($headers) ? $headers : [];
        $payload = Expr::evaluate($ctx->option('payload'), $ctx->inputs);

        $ctx->emit(RunEvent::log('info', "webhook_out → {$url}", $ctx->node->id));
        $response = $this->http->send('POST', $url, $headers, $payload);

        return ['sent' => true, 'status' => $response['status'] ?? null, 'response' => $response['body'] ?? null];
    }
}
