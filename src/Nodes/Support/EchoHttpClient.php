<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * A deterministic {@see HttpClient} that makes no network calls — it echoes the
 * request back as a 200 response. Lets flows with HTTP nodes run in tests and
 * local dev without hitting the wire. Records every request in {@see $requests}.
 */
final class EchoHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,mixed>,body:mixed}> */
    public array $requests = [];

    public function send(string $method, string $url, array $headers = [], mixed $body = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => ['echoed' => ['method' => $method, 'url' => $url, 'body' => $body]],
        ];
    }
}
