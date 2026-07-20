<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Adapters\LaravelAiLlmClient;
use FancyFlow\Capabilities\Adapters\PrismLlmClient;
use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\FakeLlmClient;
use FancyFlow\Capabilities\LlmClientDetector;
use FancyFlow\Capabilities\LlmRouteChoice;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Schema\FlowGraph;

afterEach(fn () => Capabilities::reset());

it('returns the registered client and unregisters cleanly', function () {
    $client = FakeLlmClient::always('a');

    $unregister = Capabilities::setLlmClient($client);
    expect(Capabilities::llmClient())->toBe($client);

    $unregister();
    expect(Capabilities::llmClient())->not->toBe($client);
});

it('lets a hand-rolled client replace whatever would be auto-detected', function () {
    // Hand-rolled stays first-class: registering your own is how you opt out of
    // the shipped adapters entirely.
    Capabilities::configureLlm(['driver' => 'prism', 'model' => 'claude-sonnet-4-5']);
    $mine = FakeLlmClient::always('mine');
    Capabilities::setLlmClient($mine);

    expect(Capabilities::llmClient())->toBe($mine);
});

it('builds the adapter named by the configured driver', function () {
    Capabilities::configureLlm(['driver' => LlmClientDetector::PRISM, 'model' => 'claude-sonnet-4-5']);
    expect(Capabilities::llmClient())->toBeInstanceOf(PrismLlmClient::class);

    Capabilities::configureLlm(['driver' => LlmClientDetector::LARAVEL_AI]);
    expect(Capabilities::llmClient())->toBeInstanceOf(LaravelAiLlmClient::class);
})->skip(
    fn () => count(LlmClientDetector::available()) < 2,
    'needs both prism-php/prism and laravel/ai installed',
);

it('refuses to guess when a configured driver is not installed', function () {
    Capabilities::configureLlm(['driver' => 'ollama-flavoured-thing']);

    expect(Capabilities::llmClient())->toBeNull();
    expect(Capabilities::llmUnavailableMessage())
        ->toContain('not installed')
        ->toContain('ollama-flavoured-thing');
});

it('refuses to pick a provider for you when both libraries are installed', function () {
    Capabilities::configureLlm([]);

    expect(Capabilities::llmClient())->toBeNull();
    expect(Capabilities::llmUnavailableMessage())
        ->toContain('both prism-php/prism and laravel/ai are installed')
        ->toContain('fancy-flow.llm.driver');
})->skip(
    fn () => count(LlmClientDetector::available()) !== 2,
    'only meaningful with both libraries installed',
);

it('detects the sole installed library with no configuration at all', function () {
    Capabilities::configureLlm([]);

    expect(Capabilities::llmClient())->not->toBeNull();
})->skip(
    fn () => count(LlmClientDetector::available()) !== 1,
    'only meaningful with exactly one library installed',
);

it('names what to install when nothing is available', function () {
    Capabilities::configureLlm([]);

    expect(Capabilities::llmUnavailableMessage())
        ->toContain('composer require prism-php/prism')
        ->toContain('composer require laravel/ai')
        ->toContain('Capabilities::setLlmClient()');
})->skip(
    fn () => LlmClientDetector::available() !== [],
    'only meaningful with neither library installed',
);

it('lists the supported libraries that are installed', function () {
    // Guards the class_exists() probes themselves: both are dev dependencies
    // here, so a rename in either SDK surfaces as a failure rather than as a
    // silently un-detectable adapter.
    expect(LlmClientDetector::available())
        ->toBe([LlmClientDetector::PRISM, LlmClientDetector::LARAVEL_AI]);
})->skip(
    fn () => ! PrismLlmClient::isAvailable() || ! LaravelAiLlmClient::isAvailable(),
    'needs both libraries installed',
);

it('reports which capabilities are wired before a run needs them', function () {
    Capabilities::configureLlm(['driver' => 'nope']);
    expect(Capabilities::status())->toBe(['llm' => false, 'workflow_resolver' => false]);

    Capabilities::setLlmClient(FakeLlmClient::always('a'));
    Capabilities::setWorkflowResolver(new class implements WorkflowResolver
    {
        public function resolve(string $ref): ?FlowGraph
        {
            return null;
        }
    });

    expect(Capabilities::status())->toBe(['llm' => true, 'workflow_resolver' => true]);
});

it('repeats a single scripted choice and consumes a longer script in order', function () {
    $always = FakeLlmClient::always('a');
    $request = new \FancyFlow\Capabilities\LlmRouteRequest('p', [new \FancyFlow\Capabilities\LlmRoute('a')]);
    expect($always->chooseRoute($request)->port)->toBe('a');
    expect($always->chooseRoute($request)->port)->toBe('a');

    $script = new FakeLlmClient([new LlmRouteChoice('a'), 'b']);
    expect($script->chooseRoute($request)->port)->toBe('a');
    expect($script->chooseRoute($request)->port)->toBe('b');
    expect($script->requests)->toHaveCount(2);
});
