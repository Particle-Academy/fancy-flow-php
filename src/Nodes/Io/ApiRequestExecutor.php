<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Io;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\HttpClient;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `api_request` — an HTTP request to any URL via an {@see HttpClient}. The URL
 * and body are resolved through {@see Expr} against the node's inputs. Returns
 * the client's `{status, headers, body}` response.
 */
final class ApiRequestExecutor implements NodeExecutor
{
    public function __construct(private readonly HttpClient $http) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $method = strtoupper((string) $ctx->option('method', 'GET'));
        $url = Expr::text(Expr::evaluate($ctx->option('url', ''), $ctx->inputs));
        $headers = $ctx->option('headers', []);
        $headers = is_array($headers) ? $headers : [];
        $body = Expr::evaluate($ctx->option('body'), $ctx->inputs);

        $ctx->emit(RunEvent::log('info', "api_request {$method} {$url}", $ctx->node->id));

        return $this->http->send($method, $url, $headers, $body);
    }
}
