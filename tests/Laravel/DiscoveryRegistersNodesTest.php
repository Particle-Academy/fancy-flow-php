<?php

declare(strict_types=1);

use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\FancyFlowServiceProvider;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Workflow;

/**
 * A host that sets `fancy-flow.discover` gets its nodes registered AND runnable.
 *
 * Discovery happens in the provider's `boot()`, which has already run by the time
 * a test body executes. So the test sets the path and registers the provider
 * again: on an app that has booted, `register()` boots the provider straight
 * away, which is the same `boot()` a host's app runs.
 */
beforeEach(function () {
    config()->set('fancy-flow.discover', [__DIR__.'/../Fixtures/DiscoveredNodes']);
    app()->register(FancyFlowServiceProvider::class, force: true);
});

it('registers each discovered kind', function () {
    $kinds = app(NodeKindRegistry::class);

    expect($kinds->has('discovered_echo'))->toBeTrue()
        ->and($kinds->has('discovered_braced_two'))->toBeTrue()
        ->and($kinds->has('discovered_deep'))->toBeTrue()
        ->and($kinds->has('discovered_abstract'))->toBeFalse();
});

it('runs a discovered node through its executor', function () {
    $result = app(FancyFlowManager::class)->run([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => [
            'nodes' => [
                ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'e', 'kind' => 'discovered_echo', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
            ],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'e']],
        ],
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->output('e'))->toBe(['tag' => 'echoed']);
});
