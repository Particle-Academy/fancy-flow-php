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
