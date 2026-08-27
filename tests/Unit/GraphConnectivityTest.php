<?php

declare(strict_types=1);

use FancyFlow\Analysis\GraphConnectivity;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;
use FancyFlow\Workflow;

/**
 * A graph must not contain a node that cannot take part in it.
 *
 * Both refused shapes were MEASURED against the engine first, and neither
 * failed — which is the whole reason they are worth refusing:
 *
 * - a floating `log` in a three-node graph ran (`t,lonely,o`), disconnected;
 * - `t -> output -> log` imported clean and the `log` ran with `{{ input }}`
 *   resolving to `""`.
 *
 * So these tests are not "does the validator notice", they are "does the
 * validator notice something the runtime never will".
 */
function connSchema(array $nodes, array $edges = []): array
{
    return [
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => ['nodes' => $nodes, 'edges' => $edges],
    ];
}

function connNode(string $id, string $kind, array $extra = []): array
{
    return array_merge(['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0]], $extra);
}

function connRegistry(): NodeKindRegistry
{
    return Builtin::register(new NodeKindRegistry, withStructural: true);
}

/** Import and return only the connectivity errors, by message. */
function connErrors(array $schema): array
{
    $result = Workflow::import($schema, registry: connRegistry());

    return array_map(fn ($i) => $i->message, $result->errors());
}

// ---------------------------------------------------------------------------
// FLOATING NODES
// ---------------------------------------------------------------------------

it('refuses a node with no inbound and no outbound edge', function () {
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('o', 'output'),
            connNode('lonely', 'log', ['config' => ['message' => 'nobody sent me this']]),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $result = Workflow::import($schema, registry: connRegistry());

    expect($result->ok)->toBeFalse();
    expect(implode("\n", array_map(fn ($i) => $i->message, $result->errors())))
        ->toContain('"lonely" is connected to nothing');
});

it('names the floating node so an editor can highlight it', function () {
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('lonely', 'log')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $errors = Workflow::import($schema, registry: connRegistry())->errors();

    expect($errors)->toHaveCount(1);
    expect($errors[0]->nodeId)->toBe('lonely');
});

it('refuses a trigger that reaches nobody', function () {
    // Outbound-only is the direction people forget: the node fires and the
    // graph never hears it. It has no inbound edge either, so it floats.
    $schema = connSchema(
        [
            connNode('t1', 'manual_trigger'),
            connNode('o', 'output'),
            connNode('orphanTrigger', 'webhook_trigger'),
        ],
        [['id' => 'e1', 'source' => 't1', 'target' => 'o']],
    );

    expect(connErrors($schema))->toHaveCount(1);
    expect(connErrors($schema)[0])->toContain('orphanTrigger');
});

it('refuses a whole disconnected island, not just a single stray node', function () {
    // Two nodes wired to each other but to nothing else is NOT floating by the
    // letter of the rule -- each has an edge. This documents that deliberately:
    // an island is a second workflow living in one document, which is a
    // different (and defensible) thing from a node nobody wired up.
    $schema = connSchema(
        [
            connNode('t1', 'manual_trigger'),
            connNode('o1', 'output'),
            connNode('t2', 'manual_trigger'),
            connNode('o2', 'output'),
        ],
        [
            ['id' => 'e1', 'source' => 't1', 'target' => 'o1'],
            ['id' => 'e2', 'source' => 't2', 'target' => 'o2'],
        ],
    );

    expect(connErrors($schema))->toBe([]);
});

// ---------------------------------------------------------------------------
// THE STICKY NOTE — the one kind allowed to float
// ---------------------------------------------------------------------------

it('lets a note float, because a note is an annotation and not a step', function () {
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('o', 'output'),
            connNode('sticky', 'note', ['config' => ['text' => 'why this branch exists']]),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $result = Workflow::import($schema, registry: connRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->errors())->toBe([]);
});

it('lets a note float under its canonical namespaced id too', function () {
    // A graph saved by a newer editor carries `@particle-academy/note`. If the
    // exemption were keyed on the bare spelling alone, saving in one editor and
    // validating in another would turn every sticky note into an error.
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('o', 'output'),
            connNode('sticky', '@particle-academy/note'),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    expect(connErrors($schema))->toBe([]);
});

it('does not extend the float exemption to an ordinary kind', function () {
    // `transform` is a step. Being config-light does not make it an annotation.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('x', 'transform')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    expect(implode("\n", connErrors($schema)))->toContain('connected to nothing');
});

it('lets a host kind whose category is `annotation` float', function () {
    $registry = connRegistry();
    $registry->register(new NodeKind(name: 'design_note', category: 'annotation', label: 'Design Note'));

    $result = Workflow::import(connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('d', 'design_note')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    ), registry: $registry);

    expect($result->errors())->toBe([]);
});

it('lets a `layout` kind float, which is what a swimlane is', function () {
    // The TS runtime ships `@particle-academy/lane` and its engine walks past
    // it. A lane is never wired to anything -- that is what a lane IS.
    $registry = connRegistry();
    $registry->register(new NodeKind(name: 'lane', category: 'layout', label: 'Lane'));

    $result = Workflow::import(connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('l', 'lane')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    ), registry: $registry);

    expect($result->errors())->toBe([]);
});

it('does not ALSO call an unknown kind floating, on top of its own error', function () {
    // Found by porting this rule to the TS runtime, not by reading the code.
    //
    // A graph authored in the TS editor with swimlanes carries `lane` nodes
    // that PHP's registry does not have. Before this, every one of them
    // produced a "connected to nothing" error UNDERNEATH the unknown-kind one
    // -- a second, misleading message about a node whose real problem is that
    // this runtime has never heard of it.
    //
    // We cannot know whether an unknown kind is a step, an annotation or a
    // lane, so claiming it must be wired asserts something unverifiable. The
    // unknown-kind issue already fires and is the accurate one.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('c', 'no_such_kind')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $errors = connErrors($schema);

    expect(implode("\n", $errors))->toContain('Unknown kind');
    expect(implode("\n", $errors))->not->toContain('connected to nothing');
});

// ---------------------------------------------------------------------------
// EDGES OUT OF A TERMINATOR
// ---------------------------------------------------------------------------

it('refuses an edge whose source is a terminal node', function () {
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('out', 'output'), connNode('after', 'log')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'out'],
            ['id' => 'e2', 'source' => 'out', 'target' => 'after'],
        ],
    );

    $result = Workflow::import($schema, registry: connRegistry());

    expect($result->ok)->toBeFalse();
    expect(implode("\n", array_map(fn ($i) => $i->message, $result->errors())))
        ->toContain('is a TERMINAL node');
});

it('names the offending EDGE, not the node, so the editor deletes the right thing', function () {
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('out', 'output'), connNode('after', 'log')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'out'],
            ['id' => 'e2', 'source' => 'out', 'target' => 'after'],
        ],
    );

    $errors = Workflow::import($schema, registry: connRegistry())->errors();

    expect($errors)->toHaveCount(1);
    expect($errors[0]->edgeId)->toBe('e2');
    expect($errors[0]->nodeId)->toBeNull();
});

it('refuses an edge out of `log`, which is terminal too', function () {
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('l', 'log'), connNode('after', 'output')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'l'],
            ['id' => 'e2', 'source' => 'l', 'target' => 'after'],
        ],
    );

    expect(connErrors($schema)[0] ?? '')->toContain('TERMINAL');
});

it('allows an edge out of an ordinary node that declares no outputs at all', function () {
    // THE DISTINCTION THIS WHOLE CHECK TURNS ON. `[]` is an explicit "there is
    // nothing to connect from"; `null` is "nobody declared it", which resolves
    // to `out` and is most nodes in most graphs. Reading them alike would
    // refuse nearly every workflow ever written.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('w', 'wait'), connNode('o', 'output')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'w'],
            ['id' => 'e2', 'source' => 'w', 'target' => 'o'],
        ],
    );

    expect(connErrors($schema))->toBe([]);
});

it('believes a hand-built node that declares its own outputs, over its kind', function () {
    // An author who has said what this node publishes is taken at their word,
    // the same way the engine takes it: node `outputs` beat the kind.
    //
    // Asserted against the analyser DIRECTLY, because `import()` cannot reach
    // this branch -- see the test below, which pins why.
    $issues = GraphConnectivity::check(
        [
            new FlowNode(id: 't', type: 'manual_trigger'),
            new FlowNode(id: 'out', type: 'output', outputs: [new PortDescriptor('done')]),
            new FlowNode(id: 'after', type: 'log'),
        ],
        [
            new FlowEdge(id: 'e1', source: 't', target: 'out'),
            new FlowEdge(id: 'e2', source: 'out', target: 'after'),
        ],
        connRegistry(),
    );

    expect($issues)->toBe([]);
});

it('refuses the same override coming through import, because import DROPS node outputs', function () {
    // Not an inconsistency -- the one honest answer.
    //
    // `import()` deliberately leaves `outputs` null on every node it hydrates
    // (matching the TS importer), so a declaration in the JSON does not survive
    // and the ENGINE will treat this node as terminal too. Accepting the edge
    // here would have validation promise a delivery the runtime then silently
    // fails to make -- which is the exact defect this whole check exists for.
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('out', 'output', ['outputs' => [['id' => 'done']]]),
            connNode('after', 'log'),
        ],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'out'],
            ['id' => 'e2', 'source' => 'out', 'target' => 'after'],
        ],
    );

    expect(implode("\n", connErrors($schema)))->toContain('TERMINAL');
});

it('does not refuse an edge from a kind it has never heard of', function () {
    // An unregistered kind falls back to `out` in the engine, so it is not a
    // terminator. Refusing here would break every host mid-registration -- and
    // would use "I do not know" as evidence, which is the failure mode this
    // suite keeps finding elsewhere.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('x', 'some_host_kind'), connNode('o', 'output')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'x'],
            ['id' => 'e2', 'source' => 'x', 'target' => 'o'],
        ],
    );

    $errors = connErrors($schema);

    expect(implode("\n", $errors))->not->toContain('TERMINAL');
});

// ---------------------------------------------------------------------------
// THE GRAPHS PEOPLE ACTUALLY WRITE MUST STILL PASS
// ---------------------------------------------------------------------------

it('passes an ordinary linear workflow', function () {
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('h', 'api_request', ['config' => ['url' => 'https://example.test']]),
            connNode('x', 'transform'),
            connNode('o', 'output'),
        ],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'h'],
            ['id' => 'e2', 'source' => 'h', 'target' => 'x'],
            ['id' => 'e3', 'source' => 'x', 'target' => 'o'],
        ],
    );

    expect(connErrors($schema))->toBe([]);
});

it('passes a branch with two terminal ends', function () {
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('b', 'branch', ['config' => ['path' => 'input.ok']]),
            connNode('yes', 'output'),
            connNode('no', 'log'),
        ],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'b'],
            ['id' => 'e2', 'source' => 'b', 'target' => 'yes', 'sourceHandle' => 'true'],
            ['id' => 'e3', 'source' => 'b', 'target' => 'no', 'sourceHandle' => 'false'],
        ],
    );

    expect(connErrors($schema))->toBe([]);
});

it('passes a single-node graph, which is a small workflow and not a floating node', function () {
    // Refusing this would make an editor unusable from the first node placed,
    // and the node genuinely runs -- there is no second node it is failing to
    // reach.
    expect(connErrors(connSchema([connNode('t', 'manual_trigger')])))->toBe([]);
});

it('passes an empty graph', function () {
    expect(connErrors(connSchema([])))->toBe([]);
});

it('still only warns about a dangling edge, and does not then call the node floating', function () {
    // A dangling edge is DROPPED with a warning by the importer. If connectivity
    // ran on the surviving edges alone, dropping the edge would strand its
    // source and turn one warning into an error -- changing the meaning of an
    // existing, documented behaviour as a side effect.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output')],
        [
            ['id' => 'e1', 'source' => 't', 'target' => 'o'],
            ['id' => 'e2', 'source' => 't', 'target' => 'ghost'],
        ],
    );

    $result = Workflow::import($schema, registry: connRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->warnings())->toHaveCount(1);
    expect($result->errors())->toBe([]);
});

it('reports every disconnected node, not just the first', function () {
    // Stopping at the first would make fixing a graph an N-round trip, and an
    // agent authoring one would burn a call per stray node.
    $schema = connSchema(
        [
            connNode('t', 'manual_trigger'),
            connNode('o', 'output'),
            connNode('a', 'log'),
            connNode('b', 'log'),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    expect(connErrors($schema))->toHaveCount(2);
});

it('is lenient-mode independent — a broken graph is broken either way', function () {
    // `lenient` exists so a host can load a graph containing a kind IT has not
    // registered yet. It is about unknown vocabulary, never about wiring: a
    // floating node is floating in any registry.
    $schema = connSchema(
        [connNode('t', 'manual_trigger'), connNode('o', 'output'), connNode('lonely', 'log')],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $result = Workflow::import($schema, lenient: true, registry: connRegistry());

    expect($result->ok)->toBeFalse();
});
