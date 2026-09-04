<?php

declare(strict_types=1);

use FancyFlow\Attributes\FlowNode;
use FancyFlow\Registry\NodeKind;

/*
 * `#[FlowNode]` must be able to declare the whole non-rendering NodeKind, not a
 * subset of it.
 *
 * ## Why this matters
 *
 * A Laravel host discovers custom kinds from the attribute alone. Anything the
 * attribute cannot say is simply LOST at discovery — `toKindArray()` never
 * emits it, so `NodeKind::fromArray()` never sees it, and the registered kind
 * reports null for a fact the executor knows perfectly well.
 *
 * That was true of five fields: `accent`, `defaultConfig`, `pausesForHuman`,
 * `outputShape` and `emits`. The visible damage was worse than a missing label:
 * a human-pausing kind advertised `pausesForHuman = null`, so nothing
 * downstream could tell it would stop and wait for a person; and every custom
 * kind advertised no output shape, forcing the host to keep a parallel
 * output-shape table beside the executor — two definitions of one thing, which
 * is the exact drift co-located discovery exists to prevent.
 *
 * ## The closure question
 *
 * PHP attributes hold constant expressions, so a closure cannot live in one.
 * That does NOT mean dynamic shapes are unreachable from an attribute:
 * `NodeKind::DYNAMIC_OUTPUT_SHAPE` is a plain string marker that `fromArray()`
 * turns back into a closure yielding null — "a shape exists, this process
 * cannot resolve it". Config-declared kinds keep passing real closures as
 * before; nothing here narrows that.
 */

it('carries every non-rendering NodeKind field through discovery', function () {
    $attr = new FlowNode(
        name: '@acme/approve',
        category: 'human',
        label: 'Approve',
        accent: '#a855f7',
        defaultConfig: ['timeout' => 3600],
        pausesForHuman: 'approval',
        outputShape: [['path' => 'approved', 'type' => 'boolean']],
        emits: 'input',
    );

    $kind = NodeKind::fromArray($attr->toKindArray());

    expect($kind->accent)->toBe('#a855f7');
    expect($kind->defaultConfig)->toBe(['timeout' => 3600]);
    expect($kind->pausesForHuman)->toBe('approval');
    expect($kind->emitsFor([]))->toBe('input');
    expect($kind->outputShapeFor([]))->toBe([['path' => 'approved', 'type' => 'boolean']]);
});

it('lets an attribute declare a DYNAMIC output shape, which a closure cannot be', function () {
    // The whole point of the marker: an attribute cannot hold a closure, but it
    // can still say "there IS a shape and it depends on config". Reading that as
    // "emits nothing" is the failure the field exists to prevent.
    $attr = new FlowNode(
        name: '@acme/passthrough',
        outputShape: NodeKind::DYNAMIC_OUTPUT_SHAPE,
    );

    $kind = NodeKind::fromArray($attr->toKindArray());

    expect($kind->hasDynamicOutputShape())->toBeTrue();
    expect($kind->outputShapeFor([]))->toBeNull();
});

it('omits what was not declared, so absent stays distinct from empty', function () {
    // `outputShape: []` is a positive claim — "emits no fields". A kind that
    // simply did not declare one must NOT come back as that, or a consumer
    // reads silence as an assertion.
    $kind = NodeKind::fromArray((new FlowNode(name: '@acme/plain'))->toKindArray());

    expect($kind->accent)->toBeNull();
    expect($kind->pausesForHuman)->toBeNull();
    expect($kind->outputShape)->toBeNull();
    expect($kind->emits)->toBeNull();
    expect($kind->defaultConfig)->toBe([]);

    $raw = (new FlowNode(name: '@acme/plain'))->toKindArray();
    expect($raw)->not->toHaveKey('outputShape');
    expect($raw)->not->toHaveKey('emits');
    expect($raw)->not->toHaveKey('pausesForHuman');
});

it('round-trips through NodeKind::toArray, so a manifest keeps the same facts', function () {
    // Discovery is not the only consumer: the kind is serialised into manifests
    // that registry consumers read. A field that survives fromArray but is
    // dropped by toArray is still invisible where it matters.
    $attr = new FlowNode(
        name: '@acme/approve',
        accent: '#a855f7',
        defaultConfig: ['timeout' => 3600],
        pausesForHuman: 'approval',
        outputShape: [['path' => 'approved', 'type' => 'boolean']],
        emits: 'input',
    );

    $serialised = NodeKind::fromArray($attr->toKindArray())->toArray();

    expect($serialised['accent'])->toBe('#a855f7');
    expect($serialised['defaultConfig'])->toBe(['timeout' => 3600]);
    expect($serialised['pausesForHuman'])->toBe('approval');
    expect($serialised['emits'])->toBe('input');
    expect($serialised['outputShape'])->toBe([['path' => 'approved', 'type' => 'boolean']]);
});
