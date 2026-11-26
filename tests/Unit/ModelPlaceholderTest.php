<?php

declare(strict_types=1);

/**
 * The `model` placeholder names a current model.
 *
 * The LLM kinds suggested `claude-sonnet-4-5`, which retires on 2026-11-30. A
 * placeholder is what an author (or an agent reading the kind manifest) copies,
 * so a retired id there becomes a node that fails on its first call.
 */

use FancyFlow\Authoring\FlowAuthoring;

it('suggests a current model on every model field', function () {
    $fields = [];
    foreach (FlowAuthoring::default()->kinds()->all() as $kind) {
        foreach ($kind->configSchema as $field) {
            if ($field->key === 'model') {
                $fields[$kind->name] = $field->placeholder;
            }
        }
    }

    // Vacuity guard: llm_call, llm_router and agent all declare one.
    expect(count($fields))->toBeGreaterThanOrEqual(3);
    foreach ($fields as $kind => $placeholder) {
        expect($placeholder)->toBe('claude-sonnet-5-5', $kind);
    }
});

it('suggests claude-sonnet-4-5 nowhere in a kind config schema', function () {
    $schemas = array_map(
        fn ($kind) => array_map(fn ($f) => $f->toArray(), $kind->configSchema),
        FlowAuthoring::default()->kinds()->all(),
    );

    expect(json_encode($schemas))->not->toContain('claude-sonnet-4-5');
});
