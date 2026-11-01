<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;

/**
 * `transform`: the editor's defaults are admitted, and fields mode runs.
 *
 * The TypeScript editor writes `{mode: "fields", fields: [{key: "", value: ""}]}`
 * for a new transform node. This twin declared ONLY a required `expression`, so
 * `validateConfig` refused the editor's own defaults, and fields mode did not
 * exist here: a graph built field by field passed its input straight through.
 * Reported by Maipa against the Python port, which had the same declaration.
 *
 * Every expectation, including each issue message, was read off
 * `@particle-academy/fancy-flow` 0.82.0 (`transformPath`, `validateConfig`),
 * the reference for this kind.
 */
const TFM_DEFAULTS = ['mode' => 'fields', 'fields' => [['key' => '', 'value' => '']]];

function tfmRegistry(): NodeKindRegistry
{
    return Builtin::register(new NodeKindRegistry(), withStructural: true);
}

function tfmKind(): NodeKind
{
    $kind = tfmRegistry()->get('transform');
    expect($kind)->not->toBeNull();

    return $kind;
}

/** @param array<string,mixed>|null $config */
function tfmRun(?array $config, mixed $payload): mixed
{
    $node = ['id' => 'tf', 'kind' => 'transform', 'position' => ['x' => 1, 'y' => 0]];
    if ($config !== null) {
        $node['config'] = $config;
    }
    $graph = Workflow::import([
        'version' => 1,
        'graph' => [
            'nodes' => [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]], $node],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'tf']],
        ],
    ], lenient: true, registry: tfmRegistry())->graph;

    $result = (new FlowRunner)->run($graph, Builtin::executors(),
        options: new RunOptions(initialInputs: ['t' => $payload]));
    expect($result->ok)->toBeTrue();

    return $result->outputs['tf'];
}

it('defaults to exactly what the editor writes', function () {
    expect(tfmRegistry()->defaultConfigFor(tfmKind()))->toBe(TFM_DEFAULTS);
});

it('declares the same keys as the reference', function () {
    $schema = array_map(static fn ($f) => $f->toArray(), tfmKind()->configSchema);
    expect(array_column($schema, 'key'))->toBe(['mode', 'fields', 'expression']);
    expect($schema[0]['type'])->toBe('select');
    expect(array_column($schema[0]['options'], 'value'))->toBe(['fields', 'expression']);
    expect($schema[1]['type'])->toBe('repeater');
    expect($schema[1]['skipBlankRows'])->toBeTrue();
    expect(array_map(static fn ($f) => [$f['key'], $f['type'], $f['required'] ?? false], $schema[1]['fields']))
        ->toBe([['key', 'text', true], ['value', 'expression', true]]);
    expect($schema[2]['type'])->toBe('expression');
    expect($schema[2])->not->toHaveKey('required');
});

it('admits the editor defaults', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), TFM_DEFAULTS))->toBe([]);
});

it('admits expression mode with the stale default row still present', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), [
        'mode' => 'expression', 'fields' => [['key' => '', 'value' => '']], 'expression' => '{{ $json.a }}',
    ]))->toBe([]);
});

it('treats a row with no keys at all as blank', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [[]]]))->toBe([]);
});

it('still refuses an invalid config', function (array $config, array $issues) {
    expect(tfmRegistry()->validateConfig(tfmKind(), $config))->toBe($issues);
})->with([
    'a value with no key' => [
        ['mode' => 'fields', 'fields' => [['key' => '', 'value' => '{{ $json.a }}']]],
        [['key' => 'fields', 'message' => 'Output fields item 1: Key is required']],
    ],
    'an unknown mode' => [
        ['mode' => 'rows', 'fields' => []],
        [['key' => 'mode', 'message' => 'Build the output must be one of fields, expression']],
    ],
    'fields not a list' => [
        ['mode' => 'fields', 'fields' => 'name'],
        [['key' => 'fields', 'message' => 'Output fields must be a list']],
    ],
    'a row that is not an object' => [
        ['mode' => 'fields', 'fields' => ['name']],
        [['key' => 'fields', 'message' => 'Output fields item 1 must be an object']],
    ],
    'a key that is not a string' => [
        ['mode' => 'fields', 'fields' => [['key' => 5, 'value' => 'x']]],
        [['key' => 'fields', 'message' => 'Output fields item 1: Key must be a string']],
    ],
    'an expression that is not a string' => [
        ['mode' => 'expression', 'expression' => 5],
        [['key' => 'expression', 'message' => 'Expression (advanced) must be a string']],
    ],
]);

/*
 * A whitespace-only key is blank in admission exactly as in the executor:
 * TRIMMED. `['key' => '   ', 'value' => 'Configured synthetic value']` used to
 * validate with no issue (the check compared with ''), then run as a passthrough
 * because the executor trims the key, finds it blank and skips the row -- the
 * value vanished. Reported by Maipa against the published 0.65.0. Messages read
 * off `@particle-academy/fancy-flow` 0.82.1.
 */
it('refuses a whitespace-only key with a value, with the empty-key message', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), [
        'mode' => 'fields', 'fields' => [['key' => '   ', 'value' => 'Configured synthetic value']],
    ]))->toBe([['key' => 'fields', 'message' => 'Output fields item 1: Key is required']]);
});

it('refuses every whitespace the executor trims away, not only spaces', function (string $key) {
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [['key' => $key, 'value' => 'x']]]))
        ->toBe([['key' => 'fields', 'message' => 'Output fields item 1: Key is required']]);
    // ...and the executor really does drop that row: same helper, same verdict.
    expect(tfmRun(['mode' => 'fields', 'fields' => [['key' => $key, 'value' => 'x']]], ['a' => 1]))->toBe(['a' => 1]);
})->with([
    'tab' => ["\t"],
    'newline' => ["\n"],
    'crlf padded' => [" \r\n "],
    'no-break space' => ["\u{00A0}"],
    'em space' => ["\u{2003}"],
    'byte order mark' => ["\u{FEFF}"],
    'ideographic space' => ["\u{3000}"],
]);

it('skips a whitespace-only key with an empty value as a blank row', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [['key' => '   ', 'value' => '']]]))
        ->toBe([]);
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [['key' => "\t"]]]))->toBe([]);
});

it('admits a padded non-blank key and runs it under the trimmed key', function () {
    $config = ['mode' => 'fields', 'fields' => [['key' => '  name ', 'value' => '{{ $json.first }}']]];
    expect(tfmRegistry()->validateConfig(tfmKind(), $config))->toBe([]);
    expect(tfmRun($config, ['first' => 'Ada']))->toBe(['name' => 'Ada']);
});

it('keeps judging the value untrimmed: a whitespace value is a value', function () {
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [['key' => 'pad', 'value' => '  ']]]))
        ->toBe([]);
    expect(tfmRegistry()->validateConfig(tfmKind(), ['mode' => 'fields', 'fields' => [['key' => '', 'value' => '  ']]]))
        ->toBe([['key' => 'fields', 'message' => 'Output fields item 1: Key is required']]);
});

it('does not trim on a repeater that did not opt in to skipping blank rows', function () {
    $kind = NodeKind::fromArray([
        'name' => 'acme/rows', 'category' => 'logic', 'label' => 'Rows',
        'configSchema' => [[
            'type' => 'repeater', 'key' => 'rows', 'label' => 'Rows',
            'fields' => [['type' => 'text', 'key' => 'key', 'label' => 'Key', 'required' => true]],
        ]],
    ]);
    expect(tfmRegistry()->validateConfig($kind, ['rows' => [['key' => '  ']]]))->toBe([]);
});

it('passes the input through for the untouched default', function () {
    expect(tfmRun(TFM_DEFAULTS, ['a' => 1]))->toBe(['a' => 1]);
});

it('runs the defaults for a node saved with no config', function () {
    expect(tfmRun(null, ['a' => 1]))->toBe(['a' => 1]);
});

it('builds the object in fields mode, skipping blank and non-object rows', function () {
    expect(tfmRun(['mode' => 'fields', 'fields' => [
        ['key' => '', 'value' => ''],
        ['key' => ' name ', 'value' => '{{ $json.first }}'],
        ['key' => 'greeting', 'value' => 'Hi {{ $json.first }}'],
        ['key' => 'n', 'value' => 3],
        null,
        'x',
    ]], ['first' => 'Ada']))->toBe(['name' => 'Ada', 'greeting' => 'Hi Ada', 'n' => 3]);
});

it('lets a later row overwrite an earlier one with the same key', function () {
    expect(tfmRun(['mode' => 'fields', 'fields' => [['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']]], []))
        ->toBe(['a' => '2']);
});

it('ignores stale fields in expression mode', function () {
    expect(tfmRun(['mode' => 'expression', 'fields' => [['key' => 'who', 'value' => '{{ $json.user.name }}']],
        'expression' => '{{ $json.user }}'], ['user' => ['name' => 'Ada']]))->toBe(['name' => 'Ada']);
});

it('ignores a stale expression in fields mode', function () {
    expect(tfmRun(['mode' => 'fields', 'fields' => [['key' => 'who', 'value' => '{{ $json.user.name }}']],
        'expression' => '{{ $json.user }}'], ['user' => ['name' => 'Ada']]))->toBe(['who' => 'Ada']);
});

it('passes through in expression mode with a blank expression', function () {
    expect(tfmRun(['mode' => 'expression', 'expression' => '   '], ['a' => 1]))->toBe(['a' => 1]);
});

it('falls through to fields with no mode and no expression', function () {
    expect(tfmRun(['fields' => [['key' => 'b', 'value' => '{{ $json.a }}']]], ['a' => 1]))->toBe(['b' => 1]);
});

it('takes the fields path for an unknown mode, as the reference does', function () {
    expect(tfmRun(['mode' => 'rows', 'fields' => [['key' => 'b', 'value' => '{{ $json.a }}']],
        'expression' => '{{ $json }}'], ['a' => 1]))->toBe(['b' => 1]);
});

it('emits the relation of the path the executor takes', function () {
    $kind = tfmKind();
    expect($kind->emitsFor(TFM_DEFAULTS))->toBe('input');
    expect($kind->emitsFor(['mode' => 'expression', 'expression' => '   ']))->toBe('input');
    expect($kind->emitsFor(['expression' => '{{ $json.a }}']))->toBe('expression:expression');
    expect($kind->emitsFor(['mode' => 'fields', 'fields' => [['key' => 'x', 'value' => '1']],
        'expression' => '{{ $json.a }}']))->toBeNull();
});
