<?php

declare(strict_types=1);

use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Exceptions\UnreadableWorkflow;
use FancyFlow\Laravel\EloquentWorkflowResolver;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Workflow;

/**
 * `run()` refuses a graph it cannot read, instead of running an empty one.
 *
 * `toGraph()` imports leniently and used to hand back whatever graph the import
 * produced. A versionless document imported with a warning and RAN. With the
 * version now refused in every mode, the same code would have returned an empty
 * graph and reported a successful run in which nothing executed, which is worse
 * than either. A graph that cannot be read is an exception.
 */
function rruGraph(bool $withVersion): array
{
    $doc = [
        '$schema' => Workflow::SCHEMA_URL,
        'graph' => [
            'nodes' => [
                ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'o', 'kind' => 'output', 'position' => ['x' => 200, 'y' => 0], 'config' => []],
            ],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'o']],
        ],
    ];

    return $withVersion ? ['version' => 1] + $doc : $doc;
}

it('refuses to run a versionless graph', function () {
    expect(fn () => FancyFlow::run(rruGraph(withVersion: false), ['t' => ['x' => 1]]))
        ->toThrow(UnreadableWorkflow::class, 'Unsupported workflow schema version');
});

it('refuses to turn a versionless graph into a graph', function () {
    expect(fn () => app(FancyFlowManager::class)->toGraph(rruGraph(withVersion: false)))
        ->toThrow(UnreadableWorkflow::class, 'Unsupported workflow schema version');
});

it('still runs a version-1 graph', function () {
    $result = FancyFlow::run(rruGraph(withVersion: true), ['t' => ['x' => 1]]);

    expect($result->ok)->toBeTrue();
    expect($result->output('o'))->not->toBeNull();
});

it('still runs a version-1 graph whose import reported some OTHER error', function () {
    // A `log` node publishes nothing, so the edge out of it is a connectivity
    // ERROR, and `lenient` does not soften wiring either. run() has always
    // executed such a graph, and the golden parity fixture 03 pins it. Only a
    // document the importer REFUSED (nothing read at all) is refused here.
    $doc = rruGraph(withVersion: true);
    $doc['graph']['nodes'][] = ['id' => 'lg', 'kind' => 'log', 'position' => ['x' => 100, 'y' => 0], 'config' => ['level' => 'info', 'message' => 'hi']];
    $doc['graph']['edges'] = [
        ['id' => 'e1', 'source' => 't', 'target' => 'lg'],
        ['id' => 'e2', 'source' => 'lg', 'target' => 'o'],
    ];
    expect(Workflow::import($doc, lenient: true)->ok)->toBeFalse();

    expect(FancyFlow::run($doc, ['t' => ['x' => 1]])->ok)->toBeTrue();
});

it('fails a subgraph node whose nested graph it cannot read, rather than running nothing', function () {
    $nested = rruGraph(withVersion: false);
    $outer = [
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => [
            'nodes' => [
                ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'sub', 'kind' => 'subgraph', 'position' => ['x' => 200, 'y' => 0], 'config' => ['graph' => $nested]],
            ],
            'edges' => [['id' => 'e1', 'source' => 't', 'target' => 'sub']],
        ],
    ];

    $result = FancyFlow::run($outer, ['t' => ['x' => 1]]);

    expect($result->ok)->toBeFalse();
    expect((string) $result->error)->toContain('Unsupported workflow schema version');
});

it('does not resolve a stored workflow it cannot read to an empty graph', function () {
    config()->set('fancy-flow.persistence.enabled', true);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    $this->artisan('migrate')->run();

    // `version` on the ROW is the workflow's own revision; the schema inside
    // carries no schema version at all.
    \FancyFlow\Laravel\Models\Workflow::create(['name' => 'old', 'version' => 1, 'schema' => rruGraph(withVersion: false)]);

    $child = (new EloquentWorkflowResolver(app(\FancyFlow\NodeKindRegistry::class)))->resolve('old');

    // A failure, not a graph: the subflow executor aborts with its message, and
    // the save-time cycle check skips it instead of blocking the parent.
    expect($child)->toBeInstanceOf(WorkflowResolutionFailure::class);
    expect((string) $child->message)->toContain('subflow "old"')->toContain('Unsupported workflow schema version');
})->skip(fn () => ! class_exists(\Illuminate\Database\Eloquent\Model::class), 'needs Eloquent');
