<?php

declare(strict_types=1);

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Schema\WorkflowMetadata;
use FancyFlow\Workflow;

function ffRegistry(): NodeKindRegistry
{
    return Builtin::register(new NodeKindRegistry(), withStructural: true);
}

function ffSchema(array $nodes, array $edges = [], array $extra = []): array
{
    return array_merge([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => ['nodes' => $nodes, 'edges' => $edges],
    ], $extra);
}

it('imports a valid workflow schema', function () {
    $schema = ffSchema(
        [
            ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'o', 'kind' => 'output', 'position' => ['x' => 1, 'y' => 0]],
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $result = Workflow::import($schema, registry: ffRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->graph->nodes)->toHaveCount(2);
    expect($result->graph->edges)->toHaveCount(1);
    expect($result->errors())->toBe([]);
});

it('imports from a JSON string', function () {
    $json = json_encode(ffSchema([['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]]));

    $result = Workflow::import($json, registry: ffRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->graph->node('t'))->not->toBeNull();
});

it('flags an unknown kind as an error (non-lenient)', function () {
    $schema = ffSchema([['id' => 'x', 'kind' => 'no_such_kind', 'position' => ['x' => 0, 'y' => 0]]]);

    $result = Workflow::import($schema, registry: ffRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->errors())->toHaveCount(1);
    expect($result->errors()[0]->message)->toContain('Unknown kind');
});

it('downgrades unknown kinds to warnings in lenient mode', function () {
    $schema = ffSchema([['id' => 'x', 'kind' => 'no_such_kind', 'position' => ['x' => 0, 'y' => 0]]]);

    $result = Workflow::import($schema, lenient: true, registry: ffRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->warnings())->toHaveCount(1);
});

it('drops dangling edges with a warning', function () {
    $schema = ffSchema(
        [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]],
        [['id' => 'e1', 'source' => 't', 'target' => 'ghost']],
    );

    $result = Workflow::import($schema, registry: ffRegistry());

    expect($result->graph->edges)->toBe([]);
    expect($result->warnings())->toHaveCount(1);
    expect($result->warnings()[0]->message)->toContain('not found');
});

it('warns on a missing required config value', function () {
    // schedule_trigger requires `cron`
    $schema = ffSchema([['id' => 's', 'kind' => 'schedule_trigger', 'position' => ['x' => 0, 'y' => 0]]]);

    $result = Workflow::import($schema, registry: ffRegistry());

    expect($result->ok)->toBeTrue(); // config issues are warnings, not errors
    expect($result->warnings())->not->toBe([]);
});

it('rejects an unsupported schema version', function () {
    $result = Workflow::import(['version' => 99, 'graph' => ['nodes' => [], 'edges' => []]], registry: ffRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->errors()[0]->message)->toContain('Unsupported workflow schema version');
});

it('rejects a non-object schema', function () {
    $result = Workflow::import('not json at all {', registry: ffRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->errors()[0]->message)->toContain('not an object');
});

it('round-trips export → import', function () {
    $registry = ffRegistry();
    $schema = ffSchema(
        [
            ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 5, 'y' => 6]],
            ['id' => 'o', 'kind' => 'output', 'position' => ['x' => 7, 'y' => 8], 'config' => ['note' => 'hi']],
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o', 'sourceHandle' => 'out']],
    );

    $imported = Workflow::import($schema, registry: $registry);
    $exported = Workflow::export($imported->graph, new WorkflowMetadata(name: 'demo'));

    expect($exported['version'])->toBe(1);
    expect($exported['$schema'])->toBe(Workflow::SCHEMA_URL);
    expect($exported['metadata']['name'])->toBe('demo');
    expect($exported['metadata'])->toHaveKey('updatedAt');
    expect($exported['graph']['nodes'][0])->toMatchArray(['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 5.0, 'y' => 6.0]]);
    expect($exported['graph']['nodes'][1]['config'])->toBe(['note' => 'hi']);
    expect($exported['graph']['edges'][0]['sourceHandle'])->toBe('out');

    // re-import the export cleanly
    $reimported = Workflow::import($exported, registry: $registry);
    expect($reimported->ok)->toBeTrue();
    expect($reimported->graph->nodes)->toHaveCount(2);
});

it('emits valid JSON via toJson', function () {
    $imported = Workflow::import(
        ffSchema([['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]]),
        registry: ffRegistry(),
    );

    $json = Workflow::toJson($imported->graph);

    expect(json_decode($json, true))->toBeArray();
    expect(json_decode($json, true)['version'])->toBe(1);
});

it('keeps a graph\'s DECLARED INPUTS through import', function () {
    // `graph.inputs` is how a workflow says what it ACCEPTS -- the declaration
    // `RunOptions::$props` is checked against. The importer dropped it, so every
    // graph loaded here declared no inputs, and `WorkflowProps::resolve` then
    // rejected every prop with "this workflow declares no inputs".
    //
    // That made props unusable for any durable run by construction, since a
    // durable run always imports from the stored schema. The Laravel bridge not
    // passing props was the reported half; this was underneath it, and fixing
    // only the reported half would have changed nothing observable.
    $result = Workflow::import([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => [
            'inputs' => [['name' => 'content', 'type' => 'string', 'required' => true]],
            'nodes' => [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]],
            'edges' => [],
        ],
    ], lenient: true);

    expect($result->graph->inputs)->toBe([
        ['name' => 'content', 'type' => 'string', 'required' => true],
    ]);
});

it('writes declared inputs back out on export', function () {
    // The other half of the round trip. Import alone would still lose the
    // declaration the moment a Laravel app re-exported a graph designed in the
    // TypeScript editor -- which DOES emit `graph.inputs` -- so a graph could
    // pass through this runtime and come out silently undeclared.
    $graph = new FancyFlow\Schema\FlowGraph(
        nodes: [],
        edges: [],
        inputs: [['name' => 'topic', 'type' => 'string']],
    );

    $schema = Workflow::export($graph);

    expect($schema['graph']['inputs'])->toBe([['name' => 'topic', 'type' => 'string']]);
});

it('omits the inputs key entirely for a graph that declares none', function () {
    // Matches the TypeScript exporter, which writes the key only when there is
    // something to write. An always-present `"inputs": []` would change the
    // bytes of every graph ever saved, for nothing.
    $schema = Workflow::export(new FancyFlow\Schema\FlowGraph());

    expect($schema['graph'])->not->toHaveKey('inputs');
});

/*
 * SCHEMA MIGRATION — the seam, and the three properties that make it real.
 *
 * The version has always been on the document; only TypeScript acted on it.
 * PHP and Python compared it and errored, so the day schema v2 is cut every
 * stored Op hard-fails to import on both SERVER runtimes — which is where
 * durable runs resume. A parked run would become unresumable, and the fix
 * cannot be applied afterwards: the graphs are already unreadable by the code
 * that would migrate them.
 *
 * `migrate()` takes its step table as an argument precisely so these can be
 * tested. With only v1 in existence there is no old document to migrate, so a
 * seam tested against the built-in (empty) table is a check that CANNOT fail —
 * it would pass identically against a `migrate()` that returned its input and
 * did nothing, which is what this repo has now.
 */

it('migrates a PAST version forward through the step table', function () {
    $steps = [
        // A step keyed N takes a version-N document to version N+1.
        0 => function (array $s): array {
            $s['graph']['nodes'][0]['kind'] = 'manual_trigger';

            return $s;
        },
    ];

    $migrated = Workflow::migrate([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 0,
        'graph' => ['nodes' => [['id' => 't', 'kind' => 'OLD_NAME']], 'edges' => []],
    ], $steps);

    expect($migrated['version'])->toBe(Workflow::SCHEMA_VERSION);
    expect($migrated['graph']['nodes'][0]['kind'])->toBe('manual_trigger');
});

it('refuses to migrate a FUTURE version DOWNWARD', function () {
    // We can never know what a later version means. Leaving it untouched hands
    // it to the existing version check, which reports it — rather than this
    // silently "fixing" a document written by a newer runtime.
    $doc = ['$schema' => Workflow::SCHEMA_URL, 'version' => 99, 'graph' => ['nodes' => [], 'edges' => []]];

    expect(Workflow::migrate($doc, [0 => fn (array $s): array => $s]))->toBe($doc);

    $result = Workflow::import($doc);
    expect($result->ok)->toBeFalse();
});

it('leaves a document alone when the step table has no path for it', function () {
    // A gap in the table is not a licence to guess. Unchanged means the version
    // check reports it, which is the honest outcome.
    $doc = ['$schema' => Workflow::SCHEMA_URL, 'version' => 0, 'graph' => ['nodes' => [], 'edges' => []]];

    expect(Workflow::migrate($doc, []))->toBe($doc);
});

it('imports a CURRENT document unchanged — migration is not in the way', function () {
    // The compatibility guard. Every graph in the field is v1, and the seam must
    // be invisible to all of them.
    $result = Workflow::import([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => ['nodes' => [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]], 'edges' => []],
    ], lenient: true);

    expect($result->ok)->toBeTrue();
    expect($result->graph->nodes)->toHaveCount(1);
});
