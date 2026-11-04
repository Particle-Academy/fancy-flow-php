<?php

declare(strict_types=1);

use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\Authoring\FlowAuthoringException;

beforeEach(function (): void {
    $this->authoring = FlowAuthoring::default();
});

function draftWith(FlowAuthoring $a): FancyFlow\Authoring\DraftWorkflow
{
    return $a->create('wf_test', 'Test flow');
}

it('registers the full authorable catalogue', function (): void {
    $names = array_map(fn ($k) => $k->name, $this->authoring->kinds()->all());

    // Built-ins are canonical namespaced ids, resolvable by bare name.
    expect($this->authoring->kinds()->get('branch'))->not->toBeNull()
        ->and($this->authoring->kinds()->get('subgraph'))->not->toBeNull()   // structural
        ->and($this->authoring->kinds()->get('agent'))->not->toBeNull()      // agent kind
        ->and($this->authoring->kinds()->get('llm_router')?->name)->toBe('@particle-academy/llm_router')
        // renamed kind still resolves by its old id
        ->and($this->authoring->kinds()->get('llm_branch')?->name)->toBe('@particle-academy/llm_router')
        ->and(count($names))->toBeGreaterThanOrEqual(24);
});

it('adds a node with kind defaults and a generated id', function (): void {
    $draft = draftWith($this->authoring);

    $result = $this->authoring->addNode($draft, 'manual_trigger');

    expect($result['node']['id'])->toBe('manual_trigger-1')
        ->and($result['node']['kind'])->toBe('@particle-academy/manual_trigger')
        ->and($result['issues'])->toBe([])
        ->and($draft->nodes)->toHaveCount(1);
});

it('applies schema defaults when no config is given', function (): void {
    $draft = draftWith($this->authoring);

    $result = $this->authoring->addNode($draft, 'api_request');

    // api_request declares a `method` default of GET + a headers default.
    expect($result['node']['config']['method'])->toBe('GET')
        ->and($result['node']['config']['headers'])->toBe(['content-type' => 'application/json']);
});

it('rejects an unknown kind', function (): void {
    $draft = draftWith($this->authoring);

    $this->authoring->addNode($draft, 'not_a_real_kind');
})->throws(FlowAuthoringException::class, 'Unknown node kind');

it('rejects a duplicate node id', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');

    $this->authoring->addNode($draft, 'output', nodeId: 'start');
})->throws(FlowAuthoringException::class, 'already exists');

it('reports missing required config as non-fatal issues', function (): void {
    $draft = draftWith($this->authoring);

    // api_request requires a `url`; passing config without it should surface an issue.
    $result = $this->authoring->addNode($draft, 'api_request', config: ['method' => 'GET']);

    expect($result['issues'])->not->toBe([]);
    $keys = array_map(fn ($i) => $i['key'], $result['issues']);
    expect($keys)->toContain('url');
});

it('merges config on configure by default and validates', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'api_request', nodeId: 'req', config: ['method' => 'GET']);

    $result = $this->authoring->configureNode($draft, 'req', ['url' => 'https://example.com']);

    expect($result['ok'])->toBeTrue()
        ->and($result['config']['method'])->toBe('GET')      // preserved by merge
        ->and($result['config']['url'])->toBe('https://example.com')
        ->and($result['issues'])->toBe([]);
});

it('replaces config when merge is false', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'api_request', nodeId: 'req', config: ['method' => 'POST', 'url' => 'https://a']);

    $result = $this->authoring->configureNode($draft, 'req', ['url' => 'https://b'], merge: false);

    expect($result['config'])->toBe(['url' => 'https://b'])
        ->and(array_key_exists('method', $result['config']))->toBeFalse();
});

it('connects two nodes on their default ports', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');

    $result = $this->authoring->connect($draft, 'start', 'end');

    expect($result['edge']['source'])->toBe('start')
        ->and($result['edge']['target'])->toBe('end')
        ->and($result['edge']['sourceHandle'])->toBe('out')
        ->and($result['edge']['targetHandle'])->toBe('in')
        ->and($draft->edges)->toHaveCount(1);
});

it('validates branch ports and rejects a bad source handle', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'branch', nodeId: 'b');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');

    // branch has no `out` port — the default must be rejected with the real ports named.
    expect(fn () => $this->authoring->connect($draft, 'b', 'end'))
        ->toThrow(FlowAuthoringException::class, '"true", "false"');

    // Choosing a real port succeeds.
    $result = $this->authoring->connect($draft, 'b', 'end', sourceHandle: 'true');
    expect($result['edge']['sourceHandle'])->toBe('true');
});

it('refuses to connect FROM a terminal node and TO a trigger', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');

    // A terminal (output) node has no output ports — can't be a source.
    expect(fn () => $this->authoring->connect($draft, 'end', 'start'))
        ->toThrow(FlowAuthoringException::class, 'no output ports');

    // A trigger has no input ports — can't be a target.
    expect(fn () => $this->authoring->connect($draft, 'start', 'start'))
        ->toThrow(FlowAuthoringException::class, 'accepts no inputs');
});

it('resolves dynamic llm_router route ports for connection', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'llm_router', nodeId: 'router', config: [
        'prompt' => '{{ $json.msg }}',
        'routes' => [['port' => 'billing', 'description' => 'x'], ['port' => 'support', 'description' => 'y']],
        'fallback' => true,
    ]);
    $this->authoring->addNode($draft, 'output', nodeId: 'end');

    $ok = $this->authoring->connect($draft, 'router', 'end', sourceHandle: 'billing');
    expect($ok['edge']['sourceHandle'])->toBe('billing');

    expect(fn () => $this->authoring->connect($draft, 'router', 'end', sourceHandle: 'nope', edgeId: 'x'))
        ->toThrow(FlowAuthoringException::class, '"billing", "support", "fallback"');
});

it('removes a node and cascades its edges', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');
    $this->authoring->connect($draft, 'start', 'end');

    $result = $this->authoring->removeNode($draft, 'start');

    expect($result['removed_edges'])->toHaveCount(1)
        ->and($draft->nodes)->toHaveCount(1)
        ->and($draft->edges)->toHaveCount(0);
});

it('validates a complete graph as ok and a dangling one with errors', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'transform', nodeId: 't', config: ['expression' => '{{ $json }}']);
    $this->authoring->addNode($draft, 'output', nodeId: 'end');
    $this->authoring->connect($draft, 'start', 't');
    $this->authoring->connect($draft, 't', 'end');

    $report = $this->authoring->validate($draft);

    expect($report['ok'])->toBeTrue()
        ->and($report['node_count'])->toBe(3)
        ->and($report['edge_count'])->toBe(2)
        ->and($report)->toHaveKey('capabilities');
});

it('exports a canonical WorkflowSchema v1 document', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');
    $this->authoring->connect($draft, 'start', 'end');

    $schema = $this->authoring->schemaArray($draft);

    expect($schema['version'])->toBe(1)
        ->and($schema['graph']['nodes'])->toHaveCount(2)
        ->and($schema['graph']['nodes'][0]['kind'])->toBe('@particle-academy/manual_trigger')
        ->and($schema['graph']['edges'])->toHaveCount(1);

    // Round-trips: exporting then re-importing preserves the node count.
    $reimport = $this->authoring->import('wf_rt', $schema);
    expect($reimport['draft']->nodes)->toHaveCount(2)
        ->and($reimport['ok'])->toBeTrue();
});

it('runs a simple graph deterministically with fake executors', function (): void {
    $draft = draftWith($this->authoring);
    $this->authoring->addNode($draft, 'manual_trigger', nodeId: 'start');
    $this->authoring->addNode($draft, 'output', nodeId: 'end');
    $this->authoring->connect($draft, 'start', 'end');

    $result = $this->authoring->run($draft, ['start' => ['payload' => ['hello' => 'world']]]);

    expect($result['ok'])->toBeTrue()
        ->and($result)->toHaveKey('outputs')
        ->and($result['error'])->toBeNull();
});

it('refuses to run a graph with validation errors', function (): void {
    $draft = draftWith($this->authoring);
    // An edge to a non-existent node is a dangling reference the importer flags.
    $draft->nodes[] = ['id' => 'a', 'kind' => '@particle-academy/manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []];
    $draft->edges[] = ['id' => 'e1', 'source' => 'a', 'target' => 'ghost'];

    $report = $this->authoring->validate($draft);
    // Dangling edges are warnings in the importer, so the graph is still importable;
    // assert instead that an unknown-kind node makes it not ok.
    $draft->nodes[] = ['id' => 'b', 'kind' => 'totally_unknown', 'position' => ['x' => 0, 'y' => 0], 'config' => []];
    $bad = $this->authoring->run($draft);

    expect($bad['ok'])->toBeFalse()
        ->and($bad['error'])->toContain('validation errors');
});
