<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * A deterministic {@see LlmClient} for tests and offline dev — the routing
 * twin of {@see \FancyFlow\Nodes\Support\EchoLlmClient}.
 *
 * Script the answers, then assert on {@see $requests}. Ships in `src/` rather
 * than `tests/` on purpose: consumers testing their own flows need it too, and
 * a workflow test should never need an API key or a network.
 */
final class FakeLlmClient implements LlmClient
{
    /** @var list<LlmRouteRequest> Every request received, in order. */
    public array $requests = [];

    /** @var list<LlmRouteChoice> */
    private array $queue;

    /**
     * @param list<LlmRouteChoice|string> $choices returned in order; a plain string is a port.
     *        When exhausted (or empty) the FIRST declared route is returned.
     */
    public function __construct(array $choices = [])
    {
        $this->queue = array_values(array_map(
            static fn (LlmRouteChoice|string $c): LlmRouteChoice => is_string($c) ? new LlmRouteChoice($c) : $c,
            $choices,
        ));
    }

    /** Sugar for the common case: always answer with this port. */
    public static function always(string $port, ?string $reason = null): self
    {
        $fake = new self();
        $fake->queue = [new LlmRouteChoice($port, $reason)];

        return $fake;
    }

    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            return new LlmRouteChoice($request->routes[0]->port ?? '');
        }

        // A single scripted choice repeats — `always()` semantics — while a
        // longer script is consumed one call at a time.
        return count($this->queue) === 1 ? $this->queue[0] : array_shift($this->queue);
    }
}
