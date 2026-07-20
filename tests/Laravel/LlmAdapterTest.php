<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Adapters\LaravelAiLlmClient;
use FancyFlow\Capabilities\Adapters\PrismLlmClient;
use FancyFlow\Capabilities\LlmRoute;
use FancyFlow\Capabilities\LlmRouteRequest;
use Laravel\Ai\Ai;
use Laravel\Ai\StructuredAnonymousAgent;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Request as PrismStructuredRequest;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * The shipped adapters, exercised against each SDK's OWN test double — no API
 * keys, no network. These run against the real installed libraries, so an API
 * that drifts breaks here rather than in a consumer's production run.
 */
function routeRequest(?string $model = 'claude-sonnet-4-5'): LlmRouteRequest
{
    return new LlmRouteRequest(
        prompt: 'My invoice charged me twice.',
        routes: [
            new LlmRoute('billing', 'The user is asking about an invoice, refund, or payment.'),
            new LlmRoute('support', 'Anything technical.'),
        ],
        system: 'Route the support ticket.',
        provider: 'anthropic',
        model: $model,
    );
}

// ── Prism ───────────────────────────────────────────────────────────────────

it('routes through Prism structured output', function () {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured(['port' => 'billing', 'reason' => 'Duplicate charge on an invoice.'])
            ->withFinishReason(FinishReason::Stop),
    ]);

    $choice = (new PrismLlmClient())->chooseRoute(routeRequest());

    expect($choice->port)->toBe('billing')
        ->and($choice->reason)->toBe('Duplicate charge on an invoice.');
});

it('constrains Prism to the declared ports with an enum schema', function () {
    // The whole point of structured output here: the model chooses from a
    // CLOSED SET rather than writing a port name into prose we then parse.
    $fake = Prism::fake([
        StructuredResponseFake::make()->withStructured(['port' => 'support']),
    ]);

    (new PrismLlmClient())->chooseRoute(routeRequest());

    $fake->assertRequest(function (array $recorded): void {
        expect($recorded[0])->toBeInstanceOf(PrismStructuredRequest::class);

        $schema = $recorded[0]->schema()->toArray();
        expect($schema['properties']['port']['enum'])->toBe(['billing', 'support'])
            ->and($schema['required'])->toContain('port');
    });
});

it('names the missing model instead of letting Prism fail obscurely', function () {
    Prism::fake([]);

    expect(fn () => (new PrismLlmClient())->chooseRoute(routeRequest(model: null)))
        ->toThrow(\FancyFlow\Exceptions\FlowException::class, 'no model configured');
});

// ── laravel/ai ──────────────────────────────────────────────────────────────

it('routes through laravel/ai structured output', function () {
    Ai::fakeAgent(StructuredAnonymousAgent::class, [
        ['port' => 'billing', 'reason' => 'Invoice charged twice.'],
    ]);

    $choice = (new LaravelAiLlmClient())->chooseRoute(routeRequest());

    expect($choice->port)->toBe('billing')
        ->and($choice->reason)->toBe('Invoice charged twice.');
})->skip(fn () => ! LaravelAiLlmClient::isAvailable(), 'laravel/ai is not installed');

it('constrains laravel/ai to the declared ports with an enum schema', function () {
    // With no scripted response, laravel/ai's fake gateway GENERATES a value
    // from the JSON schema — so a port only comes back at all if the schema
    // really does pin `port` to the declared enum.
    Ai::fakeAgent(StructuredAnonymousAgent::class, []);

    $choice = (new LaravelAiLlmClient())->chooseRoute(routeRequest());

    expect($choice->port)->toBeIn(['billing', 'support']);
})->skip(fn () => ! LaravelAiLlmClient::isAvailable(), 'laravel/ai is not installed');
