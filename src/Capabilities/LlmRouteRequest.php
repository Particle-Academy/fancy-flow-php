<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * The question `llm_router` asks: given these routes, which one?
 *
 * The PHP twin of fancy-flow's `LlmRouteRequest`. Deliberately narrow — this is
 * a routing decision, not a chat interface — so a host can satisfy it over any
 * SDK in a few lines, and so an implementation can constrain the model to the
 * declared ports (structured output / enum) instead of parsing prose.
 */
final class LlmRouteRequest
{
    /**
     * @param list<LlmRoute> $routes     the ports the model must choose between
     * @param string|null    $system     optional framing for the decision
     * @param string|null    $credential a HOST-RESOLVED credential reference, never a raw key
     */
    public function __construct(
        public readonly string $prompt,
        public readonly array $routes,
        public readonly ?string $system = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?string $credential = null,
    ) {}

    /**
     * The declared port ids, in order.
     *
     * @return list<string>
     */
    public function ports(): array
    {
        return array_values(array_map(static fn (LlmRoute $r): string => $r->port, $this->routes));
    }
}
