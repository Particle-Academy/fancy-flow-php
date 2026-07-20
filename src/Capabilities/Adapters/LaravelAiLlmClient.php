<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities\Adapters;

use FancyFlow\Capabilities\LlmClient;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\LlmRouteRequest;
use FancyFlow\Capabilities\RoutePrompt;
use FancyFlow\Exceptions\FlowException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * `llm_router` over laravel/ai (the official Laravel AI SDK).
 *
 * OPTIONAL — `laravel/ai` lives under composer `suggest`, never `require`, and
 * every entry point is {@see isAvailable()}-guarded.
 *
 * Uses an ad-hoc structured agent (`Laravel\Ai\agent(schema: …)`), whose JSON
 * schema pins `port` to an enum of the declared routes — the model chooses from
 * a closed set rather than emitting prose to parse. The response comes back as
 * a {@see StructuredAgentResponse}, i.e. already-decoded JSON.
 */
final class LaravelAiLlmClient implements LlmClient
{
    /**
     * @param (callable(string):array<string,mixed>)|null $credentials maps a host credential
     *        REFERENCE (never a raw key) to `['provider' => …, 'model' => …]` overrides
     */
    public function __construct(
        private readonly ?string $defaultProvider = null,
        private readonly ?string $defaultModel = null,
        private readonly mixed $credentials = null,
    ) {}

    public static function isAvailable(): bool
    {
        // The ad-hoc `agent()` helper ships in laravel/ai's autoloaded
        // functions.php; the class check keeps this honest if that ever moves.
        return function_exists('Laravel\Ai\agent') && class_exists(StructuredAgentResponse::class);
    }

    public function chooseRoute(LlmRouteRequest $request): LlmRouteChoice
    {
        if (! self::isAvailable()) {
            throw new FlowException('LaravelAiLlmClient requires laravel/ai — run `composer require laravel/ai`.');
        }

        $overrides = $this->credentialOverrides($request);
        $provider = $overrides['provider'] ?? $request->provider ?? $this->defaultProvider;
        $model = $overrides['model'] ?? $request->model ?? $this->defaultModel;

        $ports = $request->ports();
        $portDescription = RoutePrompt::portDescription($request);

        // A static closure capturing only scalars/arrays: laravel/ai wraps the
        // schema in a SerializableClosure, and binding `$this` would drag this
        // adapter (and anything it holds) into that.
        $schema = static fn (JsonSchema $json): array => [
            'port' => $json->string()->enum($ports)->description($portDescription)->required(),
            'reason' => $json->string()->description(RoutePrompt::REASON_DESCRIPTION),
        ];

        $agent = \Laravel\Ai\agent(
            instructions: RoutePrompt::system($request),
            schema: $schema,
        );

        $response = $agent->prompt(
            RoutePrompt::user($request),
            provider: $provider,
            model: $model,
        );

        if (! $response instanceof StructuredAgentResponse) {
            // Structured output was requested; anything else means the SDK took
            // a path this adapter doesn't understand. Better a clear error than
            // a route scraped out of free text.
            throw new FlowException(
                'llm_router: laravel/ai returned a non-structured response ('.$response::class.'). '
                .'Expected a StructuredAgentResponse for the route choice.',
            );
        }

        return LlmRouteChoice::fromArray($response->structured);
    }

    /** @return array{provider?:string,model?:string} */
    private function credentialOverrides(LlmRouteRequest $request): array
    {
        if ($request->credential === null || ! is_callable($this->credentials)) {
            return [];
        }

        /** @var array{provider?:string,model?:string} $resolved */
        $resolved = ($this->credentials)($request->credential);

        return $resolved;
    }
}
