<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\PortResolution;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/**
 * A node's possible ports — ONE rule, for the engine and the authoring API.
 *
 * Three kinds decide their ports from their own config: `switch_case` publishes
 * one per entry in `cases`, `llm_router` one per declared route, `subflow` gains
 * `stream` in the streaming modes.
 *
 * That answer used to be computed twice. `fancy-flow-mcp`'s `PortResolver`
 * derived it from config — so `describe_node_kind` correctly told an agent that
 * a third case existed once three were configured — while the ENGINE read only
 * the kind's static declaration and reported that same port as impossible.
 *
 * **The authoring API invited an edge and the runtime called it a mistake.** An
 * agent that did exactly what it was told was marked wrong.
 *
 * Nothing compared the two, which is why it survived. These rows are that
 * comparison.
 */
it('derives switch_case ports from the cases map, not the static declaration', function () {
    $kinds = NodeKindRegistry::default();
    $node = new FlowNode('s', 'switch_case', config: [
        'value' => '{{ in.lane }}',
        'cases' => ['billing' => 'case_a', 'technical' => 'case_b', 'other' => 'case_c'],
    ]);

    // `case_c` is NOT in the kind's declaration -- the kind can only carry a
    // representative default -- and it is genuinely publishable.
    expect(PortResolution::possible($node, $kinds->get('switch_case'), $node->config))
        ->toBe(['case_a', 'case_b', 'case_c', 'default']);
});

it('always includes `default` for switch_case, even when no case names it', function () {
    // `SwitchCaseExecutor` falls back to `default` for any value with no
    // matching entry, so it can publish even when the cases map does not
    // mention it. Omitting it would make a correct fallback edge look wrong.
    $node = new FlowNode('s', 'switch_case', config: ['cases' => ['a' => 'x']]);

    expect(PortResolution::possible($node, NodeKindRegistry::default()->get('switch_case'), $node->config))
        ->toContain('default');
});

it('falls back to the kind declaration when switch_case has no cases yet', function () {
    // A half-built node must not resolve to NOTHING -- that would make every
    // edge leaving it look impossible while the author is still typing.
    $node = new FlowNode('s', 'switch_case');

    expect(PortResolution::possible($node, NodeKindRegistry::default()->get('switch_case'), []))
        ->toBe(['case_a', 'case_b', 'default']);
});

it('derives llm_router ports from its declared routes, plus fallback', function () {
    $node = new FlowNode('r', 'llm_router', config: [
        'prompt' => 'route it',
        'routes' => [['port' => 'billing'], ['port' => 'technical']],
    ]);

    expect(PortResolution::possible($node, NodeKindRegistry::default()->get('llm_router'), $node->config))
        ->toBe(['billing', 'technical', 'fallback']);
});

it('drops llm_router fallback only when it is explicitly switched off', function () {
    // Defaults ON. `fallback` is where a run goes when the model returns a port
    // that was never offered, and emitting on a port with no edge silently ends
    // the branch -- so the default matters.
    $node = new FlowNode('r', 'llm_router', config: [
        'routes' => [['port' => 'a']],
        'fallback' => false,
    ]);

    expect(PortResolution::possible($node, NodeKindRegistry::default()->get('llm_router'), $node->config))
        ->toBe(['a']);
});

it('gives subflow a `stream` port in the streaming modes only', function () {
    $kind = NodeKindRegistry::default()->get('subflow');

    $plain = new FlowNode('f', 'subflow', config: ['workflow' => 'w']);
    expect(PortResolution::possible($plain, $kind, $plain->config))->not->toContain('stream');

    foreach (['stream', 'both'] as $mode) {
        $node = new FlowNode('f', 'subflow', config: ['workflow' => 'w', 'mode' => $mode]);
        expect(PortResolution::possible($node, $kind, $node->config))->toContain('stream');
    }
});

it('lets a node OWN declared outputs win over everything', function () {
    // The document is more specific than the kind. Precedence unchanged.
    $node = new FlowNode('s', 'switch_case', config: ['cases' => ['a' => 'x']], outputs: [
        new FancyFlow\Schema\PortDescriptor('only'),
    ]);

    expect(PortResolution::possible($node, NodeKindRegistry::default()->get('switch_case'), $node->config))
        ->toBe(['only']);
});

it('treats an unregistered kind as publishing exactly `out`', function () {
    // Not ambiguous, though it looks as though it should be: `activatedPorts`
    // falls back to exactly `out` for a kind it cannot resolve.
    expect(PortResolution::possible(new FlowNode('u', 'nope'), null, []))->toBe(['out']);
});

it('does NOT warn about an edge leaving a config-derived port', function () {
    // The end-to-end consequence, and the bug as it was actually reported: an
    // agent configured three cases through the MCP -- which offered `case_c` --
    // and the engine then warned that `case_c` could never publish.
    $graph = new FlowGraph(
        [
            new FlowNode('s', 'switch_case', config: [
                'value' => '{{ in.lane }}',
                'cases' => ['billing' => 'case_a', 'other' => 'case_c'],
            ]),
            new FlowNode('a', 'sink'),
            new FlowNode('b', 'sink'),
        ],
        [
            new FlowEdge('e1', 's', 'a', sourceHandle: 'case_a'),
            new FlowEdge('e2', 's', 'b', sourceHandle: 'case_c'),
        ],
    );

    // The REAL builtin executors, so `switch_case` actually runs and completes.
    // A bare registry leaves it unbound, the node errors, it never completes,
    // and the warning -- which is keyed on the source having completed -- cannot
    // fire. Both end-to-end cases here would then pass or fail for a reason
    // unrelated to what they test, and the "does NOT warn" one would be
    // vacuously green.
    $executors = FancyFlow\Registry\Builtin::executors()
        ->bind('sink', fn (ExecutionContext $c) => 'ok');

    $result = (new FlowRunner)->run($graph, $executors, options: new FancyFlow\Runtime\RunOptions(
        initialInputs: ['s' => ['lane' => 'billing']],
    ));

    $warnings = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $warnings[] = $event->message;
        }
    }

    expect($warnings)->toBe([]);
});

it('still warns about a port no configuration could produce', function () {
    // The guard must not be widened into uselessness by the fix. A handle that
    // is in neither the cases map nor the declaration is still a mistake.
    $graph = new FlowGraph(
        [
            new FlowNode('s', 'switch_case', config: [
                'value' => '{{ in.lane }}',
                'cases' => ['billing' => 'case_a'],
            ]),
            new FlowNode('a', 'sink'),
        ],
        [new FlowEdge('e1', 's', 'a', sourceHandle: 'case_z')],
    );

    $executors = FancyFlow\Registry\Builtin::executors()->bind('sink', fn (ExecutionContext $c) => 'ok');

    $result = (new FlowRunner)->run($graph, $executors, options: new FancyFlow\Runtime\RunOptions(
        initialInputs: ['s' => ['lane' => 'billing']],
    ));

    $warnings = [];
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $warnings[] = $event->message;
        }
    }

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('"case_z"');
});
