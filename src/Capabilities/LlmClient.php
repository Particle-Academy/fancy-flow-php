<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * The only thing core asks of an LLM: given routes, pick one.
 *
 * The PHP twin of fancy-flow's `LlmClient` capability. Distinct from
 * {@see \FancyFlow\Nodes\Support\LlmClient}, which is the free-form `complete()`
 * backend the `llm_call` / `agent` nodes use — this one is a *decision* contract
 * and nothing else.
 *
 * `llm_router` is a shuttle, not an engine: it carries the routes out to
 * whichever implementation the host registered and carries the choice back. No
 * provider SDK reaches core, which is what lets an opinionated node ship as a
 * builtin without every consumer inheriting an LLM dependency.
 *
 * Implementations should constrain the model to the declared ports (structured
 * output / enum) rather than parsing a port name out of a sentence.
 */
interface LlmClient
{
    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice;
}
