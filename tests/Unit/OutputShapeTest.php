<?php

declare(strict_types=1);

use FancyFlow\Registry\NodeKind;

/**
 * `outputShape` declares the FIELDS a kind emits — not its ports.
 *
 * It existed in the TypeScript twin and in neither backend. A consumer running
 * on PHP therefore had nothing to check `{{ in.field }}` against and had to
 * hand-maintain a table derived by reading our executors' source; that table
 * drifted, and refused a legitimate `{{ in.title }}` while accepting a field
 * that does not exist. A false rejection the author cannot comply with.
 *
 * It is the fourth capability found present in one runtime and absent in the
 * others, where **absent reads as "this kind emits nothing"** — a legitimate
 * answer, so the gap is invisible. Same shape as `graph.inputs` dropped on
 * import and `sideEffects` declared by nothing.
 */
it('accepts a static field list', function () {
    $kind = NodeKind::fromArray([
        'name' => 'llm_call', 'category' => 'ai', 'label' => 'LLM Call',
        'outputShape' => [
            ['path' => 'text', 'type' => 'string', 'description' => "The model's completion."],
            ['path' => 'usage', 'type' => 'object'],
        ],
    ]);

    expect($kind->outputShapeFor([]))->toHaveCount(2);
    expect($kind->outputShapeFor([])[0]['path'])->toBe('text');
});

it('accepts a CLOSURE of config, which is the form that cannot be lost', function () {
    // The whole reason the field is not a plain array. A `user_input` emits the
    // keys its author defined and a `system_event` its event's payload; no
    // static list can know either, and those two are this port's acceptance
    // test. A plain-array port would be a legitimate-looking value that cannot
    // express them -- invisible, because an array IS a valid answer.
    $kind = new NodeKind(
        name: 'user_input',
        category: 'human',
        label: 'User Input',
        outputShape: fn (array $config) => array_map(
            fn (array $f) => ['path' => $f['key'], 'type' => 'string'],
            $config['fields'] ?? [],
        ),
    );

    $resolved = $kind->outputShapeFor(['fields' => [['key' => 'email'], ['key' => 'note']]]);

    expect($resolved)->toHaveCount(2);
    expect(array_column($resolved, 'path'))->toBe(['email', 'note']);
});

it('distinguishes NOT DECLARED from DECLARES NOTHING', function () {
    // The distinction the whole field exists to carry. null means nobody said;
    // [] means this kind genuinely emits no fields. Collapsing them is the bug.
    $undeclared = NodeKind::fromArray(['name' => 'transform', 'category' => 'logic', 'label' => 'T']);
    expect($undeclared->outputShapeFor([]))->toBeNull();
    expect($undeclared->toArray())->not->toHaveKey('outputShape');

    $emits_nothing = NodeKind::fromArray([
        'name' => 'log', 'category' => 'io', 'label' => 'Log', 'outputShape' => [],
    ]);
    expect($emits_nothing->outputShapeFor([]))->toBe([]);
});

it('a dynamic shape says so when serialised, instead of vanishing', function () {
    // A closure cannot cross a JSON boundary. If toArray() simply dropped it,
    // the manifest would say "no outputShape" -- which reads as "emits nothing"
    // and is exactly the failure this whole field exists to fix, reintroduced
    // one level down at the serialisation seam.
    $kind = new NodeKind(
        name: 'user_input',
        category: 'human',
        label: 'User Input',
        outputShape: fn (array $config) => [['path' => 'email', 'type' => 'string']],
    );

    $array = $kind->toArray();

    expect($array)->toHaveKey('outputShape');
    expect($array['outputShape'])->toBe('dynamic');

    // And it survives the round trip as "dynamic, resolve it in-process" rather
    // than decaying into a static list that would then be WRONG for every other
    // config.
    expect(NodeKind::fromArray($array)->outputShapeFor([]))->toBeNull();
    expect(NodeKind::fromArray($array)->hasDynamicOutputShape())->toBeTrue();
});

it('round-trips a static shape unchanged', function () {
    $fields = [['path' => 'text', 'type' => 'string']];
    $kind = NodeKind::fromArray([
        'name' => 'llm_call', 'category' => 'ai', 'label' => 'L', 'outputShape' => $fields,
    ]);

    expect($kind->toArray()['outputShape'])->toBe($fields);
    expect(NodeKind::fromArray($kind->toArray())->outputShapeFor([]))->toBe($fields);
});
