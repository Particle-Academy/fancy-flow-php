<?php

declare(strict_types=1);

use FancyFlow\Authoring\PortResolver;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;

beforeEach(function (): void {
    $this->registry = new NodeKindRegistry();
    Builtin::register($this->registry, withStructural: true);
    $this->registry->register(NodeKind::fromArray(Builtin::agentKind()));
});

it('defaults to a single out/in port for kinds that declare none', function (): void {
    $transform = $this->registry->get('transform');

    expect(PortResolver::outputs($transform, []))->toBe(['out'])
        ->and(PortResolver::inputs($transform, []))->toBe(['in']);
});

it('reads declared branch ports', function (): void {
    $branch = $this->registry->get('branch');

    expect(PortResolver::outputs($branch, []))->toBe(['true', 'false'])
        ->and(PortResolver::inputs($branch, []))->toBe(['in']);
});

it('treats output/log as terminal (no output ports)', function (): void {
    expect(PortResolver::outputs($this->registry->get('output'), []))->toBe([])
        ->and(PortResolver::outputs($this->registry->get('log'), []))->toBe([]);
});

it('treats triggers as having no input ports', function (): void {
    expect(PortResolver::inputs($this->registry->get('manual_trigger'), []))->toBe([]);
});

it('derives llm_router ports from config routes plus fallback', function (): void {
    $router = $this->registry->get('llm_router');

    $ports = PortResolver::outputs($router, [
        'routes' => [['port' => 'a'], ['port' => 'b'], ['port' => 'c']],
        'fallback' => true,
    ]);
    expect($ports)->toBe(['a', 'b', 'c', 'fallback']);

    $noFallback = PortResolver::outputs($router, [
        'routes' => [['port' => 'a']],
        'fallback' => false,
    ]);
    expect($noFallback)->toBe(['a']);
});

it('derives switch_case ports from config cases plus default', function (): void {
    $switch = $this->registry->get('switch_case');

    $ports = PortResolver::outputs($switch, ['cases' => ['x' => 'case_x', 'y' => 'case_y']]);
    expect($ports)->toBe(['case_x', 'case_y', 'default']);
});

it('adds a stream port to subflow when streaming', function (): void {
    $subflow = $this->registry->get('subflow');

    expect(PortResolver::outputs($subflow, ['mode' => 'output']))->toBe(['out'])
        ->and(PortResolver::outputs($subflow, ['mode' => 'stream']))->toBe(['out', 'stream'])
        ->and(PortResolver::outputs($subflow, ['mode' => 'both']))->toBe(['out', 'stream']);
});

it('is permissive for a null (unknown) kind', function (): void {
    expect(PortResolver::outputs(null, []))->toBe(['out'])
        ->and(PortResolver::inputs(null, []))->toBe(['in']);
});
