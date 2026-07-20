<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities\Adapters;

use FancyFlow\Capabilities\LlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\LlmRouteRequest;
use FancyFlow\Capabilities\RoutePrompt;
use FancyFlow\Exceptions\FlowException;
use Prism\Prism\Prism;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * `llm_router` over prism-php/prism.
 *
 * OPTIONAL — `prism-php/prism` lives under composer `suggest`, never `require`,
 * and every entry point is {@see isAvailable()}-guarded. Core keeps its
 * no-runtime-dependencies rule; apps that already use Prism get a working
 * `llm_router` with no glue.
 *
 * The choice is constrained by an {@see EnumSchema} of the declared ports, so
 * the model picks from a closed set instead of writing a port name into prose
 * that then has to be parsed back out. (It can still return something
 * unusable — a refusal, an empty structured payload — which is exactly why
 * `llm_router` re-checks the answer against the offered routes.)
 */
final class PrismLlmClient implements LlmClient
{
    /**
     * @param (callable(string):array<string,mixed>)|null $credentials maps a host credential
     *        REFERENCE (never a raw key) to Prism provider config
     */
    public function __construct(
        private readonly ?string $defaultProvider = null,
        private readonly ?string $defaultModel = null,
        private readonly mixed $credentials = null,
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(Prism::class);
    }

    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice
    {
        if (! self::isAvailable()) {
            throw new FlowException('PrismLlmClient requires prism-php/prism — run `composer require prism-php/prism`.');
        }

        $provider = $request->provider ?? $this->defaultProvider ?? 'anthropic';
        $model = $request->model ?? $this->defaultModel ?? '';

        if ($model === '') {
            // Prism needs a model and has no default. Say so here rather than
            // letting the provider fail with a generic HTTP error.
            throw new FlowException(
                'llm_router: no model configured for Prism. Set the node\'s `model` config '
                .'or config("fancy-flow.llm.model") (e.g. "claude-sonnet-4-5").',
            );
        }

        $pending = (new Prism())->structured()
            ->using($provider, $model, $this->providerConfig($request))
            ->withSystemPrompt(RoutePrompt::system($request))
            ->withPrompt(RoutePrompt::user($request))
            ->withSchema($this->schema($request));

        $response = $pending->asStructured();

        return LlmRouteChoice::fromArray($response->structured ?? []);
    }

    /**
     * A closed-set choice plus its reason. `reason` is deliberately NOT
     * required — a provider that omits it should still yield a usable route.
     */
    private function schema(LlmRouteRequest $request): ObjectSchema
    {
        return new ObjectSchema(
            name: 'route_choice',
            description: 'The single route this input should take.',
            properties: [
                new EnumSchema('port', RoutePrompt::portDescription($request), $request->ports()),
                new StringSchema('reason', RoutePrompt::REASON_DESCRIPTION),
            ],
            requiredFields: ['port'],
        );
    }

    /** @return array<string,mixed> */
    private function providerConfig(LlmRouteRequest $request): array
    {
        if ($request->credential === null || ! is_callable($this->credentials)) {
            return [];
        }

        return ($this->credentials)($request->credential);
    }
}
