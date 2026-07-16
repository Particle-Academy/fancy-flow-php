<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Clients;

use FancyFlow\Nodes\Support\HttpClient;
use Illuminate\Http\Client\Factory;

/**
 * Backs the api_request / webhook_out executors with Laravel's HTTP client, so
 * those nodes make real requests under Laravel (and are `Http::fake()`-able in
 * tests). Bound over the framework-free {@see \FancyFlow\Nodes\Support\EchoHttpClient}
 * by the service provider.
 */
final class LaravelHttpClient implements HttpClient
{
    public function __construct(private readonly Factory $http) {}

    public function send(string $method, string $url, array $headers = [], mixed $body = null): array
    {
        $request = $this->http->withHeaders($headers);

        $options = [];
        if (is_array($body)) {
            $options['json'] = $body;
        } elseif ($body !== null) {
            $options['body'] = (string) $body;
        }

        $response = $request->send(strtoupper($method), $url, $options);
        $json = $response->json();

        return [
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $json ?? $response->body(),
        ];
    }
}
