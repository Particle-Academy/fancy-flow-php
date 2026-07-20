<?php

declare(strict_types=1);

namespace FancyFlow\Marketplace;

use FancyFlow\Capabilities\LlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\LlmRouteRequest;

/**
 * A canned `LlmClient`, built from a fixture's declared stub data.
 *
 * `llm_router` cannot reach a provider in CI, so a fixture has to supply a fake.
 * The point of constructing it from the fixture JSON — rather than letting each
 * runtime bring its own — is that both engines then build the SAME fake from
 * the SAME data. Two different fakes make the fixtures parity theatre: they
 * compare two behaviours neither of which is the node's.
 *
 * Mirrors the TS runner's `installStubs`.
 */
final class FixtureLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $port,
        private readonly ?string $reason = null,
    ) {}

    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice
    {
        return new LlmRouteChoice($this->port, $this->reason);
    }
}
