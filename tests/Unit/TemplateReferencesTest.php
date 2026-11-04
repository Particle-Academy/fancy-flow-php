<?php

declare(strict_types=1);

use FancyFlow\Analysis\TemplateReferences;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Schema\ImportIssue;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Security\GraphPolicy;
use FancyFlow\Workflow;

/**
 * The save-time half of a failure that was only ever visible at run time: a
 * template naming a field its upstream step does not emit runs green and renders
 * empty. Null means UNKNOWN and refuses nothing, because a false rejection is one
 * an author cannot comply with.
 */
function refGraph(array $nodes, array $edges, array $inputs = []): array
{
    $doc = ['version' => 1, 'graph' => ['nodes' => [], 'edges' => []]];
    foreach ($nodes as $id => [$kind, $config]) {
        $doc['graph']['nodes'][] = ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
    }
    foreach ($edges as $i => $edge) {
        $doc['graph']['edges'][] = ['id' => 'e'.$i, 'source' => $edge[0], 'target' => $edge[1]]
            + (isset($edge[2]) && $edge[2] !== null ? ['sourceHandle' => $edge[2]] : [])
            + (isset($edge[3]) && $edge[3] !== null ? ['targetHandle' => $edge[3]] : []);
    }
    if ($inputs !== []) {
        $doc['graph']['inputs'] = $inputs;
    }

    return $doc;
}

function refRegistry(?NodeKind ...$extra): NodeKindRegistry
{
    $registry = new NodeKindRegistry;
    Builtin::register($registry, withStructural: true);
    $registry->register(NodeKind::fromArray(Builtin::agentKind()));
    foreach ($extra as $kind) {
        $registry->register($kind);
    }

    return $registry;
}

function refFindings(array $doc, ?NodeKindRegistry $registry = null): array
{
    $registry ??= refRegistry();
    $import = Workflow::import($doc, lenient: true, registry: $registry, templateReferences: TemplateReferences::OFF);

    return TemplateReferences::findings($import->graph->nodes, $import->graph->edges, $registry);
}

function refPaths(array $doc, ?NodeKindRegistry $registry = null): array
{
    return array_map(static fn (array $f): string => $f['nodeId'].':'.$f['path'], refFindings($doc, $registry));
}

it('finds a field the upstream step does not declare', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'hi']],
        'o' => ['log', ['message' => '{{ in.txt }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    expect(refFindings($doc))->toBe([[
        'nodeId' => 'o',
        'configKey' => 'message',
        'path' => 'in.txt',
        'fields' => ['raw', 'text', 'usage'],
    ]]);
});

it('positive control: the reference it flags really does render empty, on a green run', function (): void {
    // Without this the check could be flagging something the engine happily
    // resolves. A green run with an empty message IS the failure being reported.
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'hi']],
        'bad' => ['log', ['message' => '[{{ in.txt }}]']],
        'good' => ['log', ['message' => '[{{ in.text }}]']],
    ], [['t', 'ask'], ['ask', 'bad'], ['ask', 'good']]);

    $registry = refRegistry();
    $import = Workflow::import($doc, registry: $registry);
    $result = (new FlowRunner)->run($import->graph, Builtin::executors(), options: new RunOptions(initialInputs: []));

    expect($result->ok)->toBeTrue()
        ->and($result->outputs['bad']['logged'] ?? null)->toBe('[]')
        ->and($result->outputs['good']['logged'] ?? null)->not->toBe('[]');
    expect(refPaths($doc))->toBe(['bad:in.txt']);
});

it('refuses nothing against a kind with no declared shape: null is unknown', function (): void {
    foreach (['webhook_trigger', 'tool_use', 'subflow', 'memory_store', 'data_store'] as $kind) {
        $doc = refGraph([
            't' => ['manual_trigger', []],
            'src' => [$kind, []],
            'o' => ['log', ['message' => '{{ in.anything_at_all }}']],
        ], [['t', 'src'], ['src', 'o']]);

        expect(refFindings($doc))->toBe([], $kind);
    }
});

it('reads a declared-empty shape as "emits no fields", a different answer from unknown', function (): void {
    $silent = NodeKind::fromArray(['name' => 'silent', 'category' => 'io', 'label' => 'Silent', 'outputShape' => []]);
    $doc = refGraph([
        't' => ['manual_trigger', []],
        's' => ['silent', []],
        'o' => ['log', ['message' => '{{ in.x }}']],
    ], [['t', 's'], ['s', 'o']]);

    expect(refFindings($doc, refRegistry($silent)))->toBe([[
        'nodeId' => 'o', 'configKey' => 'message', 'path' => 'in.x', 'fields' => [],
    ]]);
});

it('resolves a dynamic shape through the node\'s own config', function (): void {
    // `data` exists only when the author asked for a schema.
    $withSchema = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x', 'response_schema' => ['type' => 'object']]],
        'o' => ['log', ['message' => '{{ in.data.score }}']],
    ], [['t', 'ask'], ['ask', 'o']]);
    $without = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => '{{ in.data.score }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    expect(refFindings($withSchema))->toBe([])
        ->and(refPaths($without))->toBe(['o:in.data.score']);
});

it('walks through a passthrough kind to the shape behind it', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'b' => ['branch', ['condition' => '{{ in.text }}']],
        'yes' => ['log', ['message' => '{{ in.txt }}']],
    ], [['t', 'ask'], ['ask', 'b'], ['b', 'yes', 'true']]);

    expect(refPaths($doc))->toBe(['yes:in.txt']);
});

it('stays unknown through a passthrough when the source is unknown', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'b' => ['branch', ['condition' => '{{ in.flag }}']],
        'yes' => ['log', ['message' => '{{ in.whatever }}']],
    ], [['t', 'b'], ['b', 'yes', 'true']]);

    expect(refFindings($doc))->toBe([]);
});

it('treats an entry point as unknown: its fields are whatever the host seeded', function (): void {
    $doc = refGraph(['t' => ['log', ['message' => '{{ in.x }} {{ y }}']]], []);

    expect(refFindings($doc))->toBe([]);
});

it('never judges the for_each item port against the kind\'s declared shape', function (): void {
    // The declared shape describes `done`. `item` carries whatever the list held,
    // so reading the shape for it would refuse every legitimate `{{ in.name }}`.
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'each' => ['for_each', ['source' => '{{ in.users }}']],
        'per' => ['log', ['message' => '{{ in.name }}']],
        'after' => ['log', ['message' => '{{ in.nope }}']],
    ], [['t', 'each'], ['each', 'per', 'item'], ['each', 'after', 'done']]);

    expect(refPaths($doc))->toBe(['after:in.nope']);
});

it('accepts a reference any source on the port declares, and is unknown if any source is', function (): void {
    $bothKnown = refGraph([
        't' => ['manual_trigger', []],
        'a' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'b' => ['api_request', ['url' => 'https://example.test/']],
        'o' => ['log', ['message' => '{{ in.status }} {{ in.text }} {{ in.zzz }}']],
    ], [['t', 'a'], ['t', 'b'], ['a', 'o'], ['b', 'o']]);
    $oneUnknown = refGraph([
        't' => ['manual_trigger', []],
        'a' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'b' => ['tool_use', ['tool' => 'x']],
        'o' => ['log', ['message' => '{{ in.zzz }}']],
    ], [['t', 'a'], ['t', 'b'], ['a', 'o'], ['b', 'o']]);

    expect(refPaths($bothKnown))->toBe(['o:in.zzz'])
        ->and(refFindings($oneUnknown))->toBe([]);
});

it('allows a path deeper than a declared field, and a prefix of one', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'h' => ['api_request', ['url' => 'https://example.test/']],
        'o' => ['log', ['message' => '{{ in.body.items.0.name }} {{ in.headers }}']],
    ], [['t', 'h'], ['h', 'o']]);

    expect(refFindings($doc))->toBe([]);

    $nested = NodeKind::fromArray(['name' => 'nested', 'category' => 'io', 'label' => 'N', 'outputShape' => [['path' => 'user.email']]]);
    $prefix = refGraph([
        't' => ['manual_trigger', []],
        'n' => ['nested', []],
        'o' => ['log', ['message' => '{{ in.user }} {{ in.user.email }} {{ in.user.phone }}']],
    ], [['t', 'n'], ['n', 'o']]);

    expect(refPaths($prefix, refRegistry($nested)))->toBe(['o:in.user.phone']);
});

it('reads every spelling of the input: in, $json, $input, a named port, a handle-less source id', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => '{{ in.a1 }} {{ $json.a2 }} {{ $input.a3 }} {{ ask.a4 }} {{ in.text }} {{ $json }} {{ in }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    expect(refPaths($doc))->toBe(['o:in.a1', 'o:$json.a2', 'o:$input.a3', 'o:ask.a4']);

    $port = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'm' => ['log', ['message' => '{{ a.text }} {{ a.nope }} {{ unrelated.thing }}']],
    ], [['t', 'ask'], ['ask', 'm', null, 'a']]);

    expect(refPaths($port))->toBe(['m:a.nope']);
});

it('does not judge a head it cannot prove, or anything that is not a dot-path', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => "{{ { id: \$json.id } }} {{ a && b }} {{ \$props.topic }} {{ answer }} {{ }} {{ unclosed"]],
    ], [['t', 'ask'], ['ask', 'o']]);

    expect(refFindings($doc))->toBe([]);
});

it('follows merge, whose fields are the union of every port', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'a' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'b' => ['api_request', ['url' => 'https://example.test/']],
        'm' => ['merge', []],
        'o' => ['log', ['message' => '{{ in.text }} {{ in.status }} {{ in.gone }}']],
    ], [['t', 'a'], ['t', 'b'], ['a', 'm', null, 'a'], ['b', 'm', null, 'b'], ['m', 'o']]);

    expect(refPaths($doc))->toBe(['o:in.gone']);

    // `concat` builds a list and declares nothing: not "no fields".
    $concat = refGraph([
        't' => ['manual_trigger', []],
        'a' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'm' => ['merge', ['mode' => 'concat']],
        'o' => ['log', ['message' => '{{ in.gone }}']],
    ], [['t', 'a'], ['a', 'm', null, 'a'], ['m', 'o']]);

    expect(refFindings($concat))->toBe([]);
});

it('follows a single-reference expression, and refuses to expand an interpolation', function (): void {
    $single = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'v' => ['variable', ['name' => 'v', 'value' => '{{ in }}']],
        'o' => ['log', ['message' => '{{ in.text }} {{ in.nope }}']],
    ], [['t', 'ask'], ['ask', 'v'], ['v', 'o']]);
    $interpolated = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'v' => ['variable', ['name' => 'v', 'value' => 'x {{ in.text }} y']],
        'o' => ['log', ['message' => '{{ in.anything }}']],
    ], [['t', 'ask'], ['ask', 'v'], ['v', 'o']]);

    expect(refPaths($single))->toBe(['o:in.nope'])
        ->and(refFindings($interpolated))->toBe([]);
});

it('reads a transform\'s fields rows, and treats its built object as unknown', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'tf' => ['transform', ['mode' => 'fields', 'fields' => [['key' => 'k', 'value' => '{{ in.txt }}']]]],
        'o' => ['log', ['message' => '{{ in.whatever }}']],
    ], [['t', 'ask'], ['ask', 'tf'], ['tf', 'o']]);

    expect(refFindings($doc))->toBe([[
        'nodeId' => 'tf', 'configKey' => 'fields.0.value', 'path' => 'in.txt', 'fields' => ['raw', 'text', 'usage'],
    ]]);
});

it('survives a cycle and a shape closure that throws on hostile config', function (): void {
    $cycle = refGraph([
        'a' => ['branch', ['condition' => '{{ in.x }}']],
        'b' => ['branch', ['condition' => '{{ in.y }}']],
    ], [['a', 'b', 'true'], ['b', 'a', 'true']]);
    expect(refFindings($cycle))->toBe([]);

    $hostile = refGraph([
        't' => ['manual_trigger', []],
        'u' => ['user_input', ['fields' => ['not-an-array']]],
        'o' => ['log', ['message' => '{{ in.x }}']],
    ], [['t', 'u'], ['u', 'o']]);
    expect(refFindings($hostile))->toBe([]);
});

it('handles a template with no closing braces in linear time', function (): void {
    $references = TemplateReferences::references(str_repeat('{{ a ', 200000));

    expect($references)->toBe([]);
});

it('warns from Workflow::import by default and never changes ok', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => '{{ in.txt }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    $import = Workflow::import($doc, registry: refRegistry());

    expect($import->ok)->toBeTrue();
    $warnings = array_values(array_filter($import->warnings(), static fn (ImportIssue $i): bool => str_contains($i->message, 'in.txt')));
    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->nodeId)->toBe('o');
});

it('becomes an error only when a host asks, and can be turned off', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => '{{ in.txt }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    $strict = Workflow::import($doc, registry: refRegistry(), templateReferences: ImportIssue::ERROR);
    $off = Workflow::import($doc, registry: refRegistry(), templateReferences: TemplateReferences::OFF);

    expect($strict->ok)->toBeFalse()
        // The graph was READ: this is not a refusal, so a run path is unaffected.
        ->and($strict->refused())->toBeFalse()
        ->and($off->issues)->toBe([]);
});

it('plugs into GraphPolicy::addRule as the host\'s way to refuse at save', function (): void {
    $doc = refGraph([
        't' => ['manual_trigger', []],
        'ask' => ['llm_call', ['model' => 'm', 'prompt' => 'x']],
        'o' => ['log', ['message' => '{{ in.txt }}']],
    ], [['t', 'ask'], ['ask', 'o']]);

    $issues = GraphPolicy::trusted()->addRule(TemplateReferences::rule(refRegistry()))->inspect($doc);

    expect(array_map(static fn (ImportIssue $i): bool => $i->isError(), $issues))->toBe([true]);
});
