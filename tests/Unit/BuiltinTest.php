<?php

declare(strict_types=1);

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;

it('registers all 22 built-in kinds', function () {
    $r = Builtin::register(new NodeKindRegistry());

    expect($r->all())->toHaveCount(22);
});

it('covers the expected 7 domains with the right counts', function () {
    $r = Builtin::register(new NodeKindRegistry());
    $counts = [];
    foreach ($r->all() as $k) {
        $counts[$k->category] = ($counts[$k->category] ?? 0) + 1;
    }
    ksort($counts);

    expect($counts)->toBe([
        'ai' => 3,
        'data' => 3,
        'human' => 3,
        'io' => 2,
        'logic' => 6,
        'output' => 2,
        'trigger' => 3,
    ]);
});

it('registers every named kind from the fancy-flow library', function () {
    $r = Builtin::register(new NodeKindRegistry());
    $expected = [
        'manual_trigger', 'webhook_trigger', 'schedule_trigger',
        'user_input', 'human_approval', 'notify',
        'branch', 'switch_case', 'for_each', 'merge', 'wait', 'transform',
        'memory_store', 'data_store', 'variable',
        'llm_call', 'tool_use', 'embed_search',
        'api_request', 'webhook_out',
        'output', 'log',
    ];

    foreach ($expected as $name) {
        expect($r->has($name))->toBeTrue("kind {$name} should be registered");
    }
});

it('adds structural kinds only when asked', function () {
    $plain = Builtin::register(new NodeKindRegistry());
    expect($plain->has('subgraph'))->toBeFalse();
    expect($plain->has('note'))->toBeFalse();

    $withStructural = Builtin::register(new NodeKindRegistry(), withStructural: true);
    expect($withStructural->has('subgraph'))->toBeTrue();
    expect($withStructural->has('note'))->toBeTrue();
    expect($withStructural->all())->toHaveCount(24);
});

it('binds a default executor for every registered kind', function () {
    $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
    $executors = Builtin::executors();

    foreach ($registry->all() as $kind) {
        if ($kind->name === 'note') {
            continue; // note is never executed
        }
        expect($executors->hasKind($kind->name))->toBeTrue("executor for {$kind->name}");
    }
});

it('preserves port declarations and config schema on a kind', function () {
    $r = Builtin::register(new NodeKindRegistry());
    $branch = $r->get('branch');

    expect(array_map(fn ($p) => $p->id, $branch->outputs))->toBe(['true', 'false']);
    expect($branch->configSchema[0]->key)->toBe('condition');
    expect($branch->configSchema[0]->required)->toBeTrue();

    $output = $r->get('output');
    expect($output->outputs)->toBe([]); // explicitly terminal
});
