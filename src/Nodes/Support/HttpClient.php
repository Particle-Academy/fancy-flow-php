<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * The HTTP transport the api_request / webhook_out executors use. The default
 * {@see EchoHttpClient} performs no network I/O (deterministic); the Laravel
 * layer binds Laravel's HTTP client.
 */
interface HttpClient
{
    /**
     * @param array<string,mixed> $headers
     * @return array{status:int,headers:array<string,mixed>,body:mixed}
     */
    public function send(string $method, string $url, array $headers = [], mixed $body = null): array;
}
