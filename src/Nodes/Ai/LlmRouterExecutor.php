<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Ai;

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\LlmClient;
use FancyFlow\Capabilities\LlmRoute;
use FancyFlow\Capabilities\LlmRouteRequest;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\PortDescriptor;

/**
 * `llm_router` — a SHUTTLE, not an engine. The PHP port of fancy-flow's
 * LLM router node (`llm-branch.ts`, renamed alongside the kind id).
 *
 * Canonical id `@particle-academy/llm_router`; the previously-shipped
 * `llm_branch` spellings remain aliases, so existing graphs keep running.
 *
 * It carries the declared routes and the decision prompt out to whatever
 * {@see LlmClient} the host registered (or the auto-detected Prism / laravel-ai
 * adapter), and carries the chosen port back down the graph. It contains no
 * provider SDK, no prompt engineering, no response parsing and no retry policy
 * — all of that belongs to the client, which is what lets this node live in
 * core without every consumer inheriting an LLM dependency.
 *
 * The one thing it does own is GRAPH INTEGRITY, because that is a workflow
 * concern rather than an AI one: a port the model invents must never route.
 */
final class LlmRouterExecutor implements NodeExecutor
{
    /** @param LlmClient|null $client an explicit client; null resolves through {@see Capabilities}. */
    public function __construct(private readonly ?LlmClient $client = null) {}

    /**
     * The node's declared routes. Blank ports are dropped — a half-typed route
     * must not become a real one.
     *
     * @param array<string,mixed> $config
     * @return list<LlmRoute>
     */
    public static function declaredRoutes(array $config): array
    {
        $raw = $config['routes'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $routes = [];
        foreach ($raw as $route) {
            if (! is_array($route)) {
                continue;
            }
            $parsed = LlmRoute::fromArray($route);
            if ($parsed->port !== '') {
                $routes[] = $parsed;
            }
        }

        return $routes;
    }

    /**
     * Where a run goes when the model returns a port that was never offered.
     *
     * Emitting on a port with no edge silently ends the branch — the worst
     * failure mode in a workflow engine, because the run then reports SUCCESS
     * having done nothing. So: the `fallback` port when it exists, else the
     * first declared route, and always loudly.
     *
     * @param list<LlmRoute> $routes
     */
    public static function resolveFallbackPort(array $routes, bool $fallbackEnabled): string
    {
        if ($fallbackEnabled) {
            return 'fallback';
        }

        // Callers only reach this with at least one declared route (a node with
        // none aborts earlier), so there is always somewhere safe to land.
        return $routes[0]->port ?? 'out';
    }

    /**
     * Ports derived from the `routes` list — the twin of the TS kind's
     * `outputs: (config) => routePorts(...)`.
     *
     * PHP {@see \FancyFlow\Registry\NodeKind} declares STATIC ports, so this is
     * exposed as a function for hosts (and the editor bridge) to call with a
     * node's config. Blank and duplicate ports are dropped so a half-typed
     * route can't collide with a real one.
     *
     * @param array<string,mixed> $config
     * @return list<PortDescriptor>
     */
    public static function ports(array $config): array
    {
        $ports = [];
        $seen = [];
        foreach (self::declaredRoutes($config) as $route) {
            if (isset($seen[$route->port])) {
                continue;
            }
            $seen[$route->port] = true;
            $ports[] = new PortDescriptor($route->port, $route->port);
        }
        if (($config['fallback'] ?? true) !== false && ! isset($seen['fallback'])) {
            $ports[] = new PortDescriptor('fallback', 'fallback');
        }
        if ($ports === []) {
            $ports[] = new PortDescriptor('out');
        }

        return $ports;
    }

    public function execute(ExecutionContext $ctx): mixed
    {
        $config = $ctx->config();
        $routes = self::declaredRoutes($config);

        if ($routes === []) {
            $ctx->abort('llm_router has no routes configured');
        }

        $client = $this->client ?? Capabilities::llmClient();
        if ($client === null) {
            // Fail loudly rather than guessing a branch. A silent default here
            // would look like the model made a choice.
            $ctx->abort(Capabilities::llmUnavailableMessage());
        }

        $fallbackEnabled = ($config['fallback'] ?? true) !== false;

        $choice = $client->chooseRoute(new LlmRouteRequest(
            prompt: $this->prompt($ctx, $config),
            routes: $routes,
            system: $this->string($config, 'system'),
            provider: $this->string($config, 'provider'),
            model: $this->string($config, 'model'),
            credential: $this->string($config, 'credential'),
        ));

        $offered = array_flip(array_map(static fn (LlmRoute $r): string => $r->port, $routes));
        $port = $choice->port;
        $reason = $choice->reason;

        if (! isset($offered[$port])) {
            $safe = self::resolveFallbackPort($routes, $fallbackEnabled);
            $ctx->emit(RunEvent::log(
                'warn',
                sprintf(
                    'llm_router: model returned "%s", which is not a declared route. Routing to "%s".',
                    $port === '' ? '(nothing)' : $port,
                    $safe,
                ),
                $ctx->node->id,
            ));
            $reason ??= sprintf('unrecognised route "%s"', $port);
            $port = $safe;
        }

        // The reason travels WITH the value, so a completed run explains itself
        // without needing the model call replayed.
        return Port::only($port, ['route' => $port, 'reason' => $reason, 'input' => $ctx->inputs]);
    }

    /** @param array<string,mixed> $config */
    private function prompt(ExecutionContext $ctx, array $config): string
    {
        $prompt = $config['prompt'] ?? null;
        if (is_string($prompt) && $prompt !== '') {
            return $prompt;
        }

        // Mirrors the TS `String(config.prompt ?? ctx.inputs ?? "")`: with no
        // prompt configured, route on whatever arrived.
        return $this->stringify($ctx->inputs);
    }

    /** @param array<string,mixed> $config */
    private function string(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
