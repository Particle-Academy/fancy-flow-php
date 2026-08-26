<?php

declare(strict_types=1);

use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;

/**
 * The builtin kinds' declared emissions, read through the registry a consumer
 * actually uses.
 *
 * Every declaration was read from its EXECUTOR's return statement, never from
 * the TypeScript twin or any other declaration. Both of the reference
 * consumer's own table errors were rows that agreed with a second artefact, so
 * "two declarations agree" is not evidence.
 */
function kind(string $name): NodeKind
{
    // `agent` is neither a default nor a structural kind -- it has its own
    // Builtin::agentKind() and is registered by hosts that want it. Reading it
    // through the registry would report "not registered" and say nothing about
    // its shape.
    if ($name === 'agent') {
        return NodeKind::fromArray(Builtin::agentKind());
    }

    $found = Builtin::register(null, true)->get($name);
    expect($found)->not->toBeNull("builtin `{$name}` is not registered");

    return $found;
}

it('declares the fields of kinds whose output is fully enumerable', function () {
    $cases = [
        'embed_search' => ['query', 'matches'],
        'api_request' => ['status', 'headers', 'body'],
        'llm_router' => ['route', 'reason', 'input'],
        'notify' => ['sent', 'channel', 'to', 'message'],
        'webhook_out' => ['sent', 'status', 'response'],
        'for_each' => ['items', 'count'],
        'wait' => ['waited', 'duration', 'input'],
        'log' => ['logged', 'level'],
        'agent' => ['text', 'steps', 'truncated'],
    ];

    foreach ($cases as $name => $expected) {
        $shape = kind($name)->outputShapeFor([]);
        expect($shape)->not->toBeNull("`{$name}` should declare a shape");
        expect(array_column($shape, 'path'))->toBe($expected, "`{$name}` fields");
    }
});

it('llm_call gains `data` only when the author asked for a schema', function () {
    // The exact case filed against the reference consumer twice, by an agent,
    // on two different workflows: `{{ in.output }}` on a kind that emits `text`.
    $llm = kind('llm_call');

    expect(array_column($llm->outputShapeFor([]), 'path'))
        ->toBe(['text', 'usage', 'raw']);

    expect(array_column($llm->outputShapeFor(['response_schema' => ['type' => 'object']]), 'path'))
        ->toBe(['text', 'data', 'usage', 'raw']);

    // An empty schema is not a schema -- otherwise `data` would be declared and
    // never arrive, which is a promised field that is always absent.
    expect(array_column($llm->outputShapeFor(['response_schema' => '']), 'path'))
        ->toBe(['text', 'usage', 'raw']);
});

it('user_input emits the keys its author defined', function () {
    $shape = kind('user_input')->outputShapeFor([
        'fields' => [
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'note', 'label' => 'Note'],
        ],
    ]);

    expect(array_column($shape, 'path'))->toBe(['email', 'note']);

    // No fields declared yet is an empty list, not "unknown": the author has
    // answered, and the answer is "nothing so far".
    expect(kind('user_input')->outputShapeFor([]))->toBe([]);
});

it('both config-dependent kinds report themselves as dynamic', function () {
    // A consumer must be able to tell "declared, unresolvable here" from
    // "nobody declared" -- the correct behaviour differs, and collapsing them
    // makes a fixed table answer a config-dependent question.
    expect(kind('llm_call')->hasDynamicOutputShape())->toBeTrue();
    expect(kind('user_input')->hasDynamicOutputShape())->toBeTrue();
    expect(kind('notify')->hasDynamicOutputShape())->toBeFalse();
});

it('leaves pass-through kinds UNDECLARED rather than guessing', function () {
    // These emit whatever arrived, so their shape is not knowable from the kind
    // alone. `null` is the honest answer and a validator must read it as
    // "unknown, do not refuse".
    //
    // schedule_trigger is here for a sharper reason: it array_merges its inputs
    // into the TOP level (ScheduleTriggerExecutor.php:23-28), so a partial list
    // of ['cron','timezone'] would make a validator refuse every merged-in key.
    // A partial static list on a merging kind is a false-rejection generator.
    foreach ([
        'branch', 'switch_case', 'output', 'transform', 'merge',
        'manual_trigger', 'webhook_trigger', 'human_approval',
        'variable',
        // schedule_trigger LEFT this list when `emits` arrived. It was here
        // because a partial list of ['cron','timezone'] would have made a
        // reader refuse every merged-in key -- but that was only true while
        // nothing could SAY the inputs merge. With `emits: 'inputs-merged'`
        // declared beside the list, the two together are complete, so the list
        // is now safe and useful rather than a false-rejection generator.
    ] as $name) {
        expect(kind($name)->outputShapeFor([]))
            ->toBeNull("`{$name}` passes input through; declaring a shape would cause false refusals");
    }
});

it('a declared shape never contains an empty path', function () {
    // An empty path is unaddressable, so it can only ever be noise a validator
    // has to special-case.
    $kinds = [...Builtin::register(null, true)->all(), NodeKind::fromArray(Builtin::agentKind())];
    foreach ($kinds as $k) {
        $shape = $k->outputShapeFor([]);
        if ($shape === null) {
            continue;
        }
        foreach ($shape as $f) {
            expect($f['path'] ?? '')->not->toBe('', "`{$k->name}` declared a field with no path");
        }
    }
});

it('declares the RELATION for kinds whose shape depends on their input', function () {
    // The half a field list cannot express. Each was read from its executor and
    // checked for MERGE vs NEST before being assigned -- a relation with no
    // destination can only describe a top-level merge.
    $cases = [
        'branch' => 'input',
        'switch_case' => 'input',
        'output' => 'input',
        'human_approval' => 'input',
        'manual_trigger' => 'input-map-merged',
        'variable' => 'expression:value',
    ];

    foreach ($cases as $name => $expected) {
        expect(kind($name)->emitsFor([]))->toBe($expected, "`{$name}` relation");
    }
});

it('transform changes relation with its config', function () {
    // TWO returns: the input unchanged when no expression is set, else the
    // expression's shape. So the RELATION itself is config-dependent.
    $transform = kind('transform');

    expect($transform->emitsFor([]))->toBe('input');
    expect($transform->emitsFor(['expression' => '']))->toBe('input');
    expect($transform->emitsFor(['expression' => '{{ in.user }}']))->toBe('expression:expression');
});

it('an expression relation names its OWN config key', function () {
    // `transform` reads config.expression; `variable` reads config.value. A
    // consumer that hardcodes "the field called expression" has copied our
    // knowledge one level down -- the thing this removes.
    expect(kind('variable')->expressionConfigKey([]))->toBe('value');
    expect(kind('transform')->expressionConfigKey(['expression' => '{{ in.x }}']))->toBe('expression');
    expect(kind('branch')->expressionConfigKey([]))->toBeNull();
});

it('merge concatenating declares NOTHING rather than an empty list', function () {
    // mode 'merge' unions its inputs at the top level; 'concat' builds a LIST,
    // whose elements are not addressable as fields. `[]` would claim "emits no
    // fields", which is false and would refuse every reference -- so null.
    $merge = kind('merge');

    expect($merge->emitsFor([]))->toBe('inputs-merged');
    expect($merge->emitsFor(['mode' => 'merge']))->toBe('inputs-merged');
    expect($merge->emitsFor(['mode' => 'concat']))->toBeNull();
    expect($merge->outputShapeFor([]))->toBeNull();
});

it('wait declares a LIST, never a relation, because it NESTS', function () {
    // WaitExecutor returns ['waited'=>…, 'duration'=>…, 'input'=>…] -- the
    // input goes UNDER a key. `emits: 'input'` would make a reader accept
    // {{ in.<any inbound field> }} at top level, which resolves to nothing at
    // run time. This is the case that proved a relation needs a destination.
    expect(kind('wait')->emitsFor([]))->toBeNull();
    expect(array_column(kind('wait')->outputShapeFor([]), 'path'))
        ->toBe(['waited', 'duration', 'input']);
});

it('schedule_trigger composes a list WITH a merge, because its merge is top-level', function () {
    // The composition case, and it is correct here where it was wrong for
    // `wait`: ScheduleTriggerExecutor array_merges into the TOP level.
    $sched = kind('schedule_trigger');

    expect($sched->emitsFor([]))->toBe('input-map-merged');
    expect(array_column($sched->outputShapeFor([]), 'path'))->toBe(['cron', 'timezone']);
});
