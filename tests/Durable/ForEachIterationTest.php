<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Workflow;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

it('parks and resumes independently for every item without repeating writes', function (string $driver): void {
    config()->set('fancy-flow.queue.driver', $driver);

    $writes = [];
    app(ExecutorRegistry::class)->bind('iteration_writer', function (ExecutionContext $ctx) use (&$writes): array {
        $writes[] = $ctx->input();

        return ['item' => $ctx->input()];
    });

    $run = FancyFlow::dispatch(
        [
            '$schema' => Workflow::SCHEMA_URL,
            'version' => 1,
            'graph' => [
                'nodes' => [
                    ['id' => 'start', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                    ['id' => 'each', 'kind' => 'for_each', 'position' => ['x' => 0, 'y' => 1], 'config' => ['source' => '{{ in.items }}']],
                    ['id' => 'write', 'kind' => 'iteration_writer', 'position' => ['x' => 0, 'y' => 2], 'config' => []],
                    ['id' => 'gate', 'kind' => 'human_approval', 'position' => ['x' => 0, 'y' => 3], 'config' => ['title' => 'Approve item']],
                    ['id' => 'end', 'kind' => 'output', 'position' => ['x' => 0, 'y' => 4], 'config' => []],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start', 'target' => 'each'],
                    ['id' => 'e2', 'source' => 'each', 'target' => 'write', 'sourceHandle' => 'item'],
                    ['id' => 'e3', 'source' => 'write', 'target' => 'gate'],
                    ['id' => 'e4', 'source' => 'each', 'target' => 'end', 'sourceHandle' => 'done'],
                ],
            ],
        ],
        ['start' => ['items' => [1, 2, 3]]],
    )->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
        ->and($run->awaiting_node)->toBe('each/0/gate')
        ->and($writes)->toBe([1]);

    $run->approve()->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
        ->and($run->awaiting_node)->toBe('each/1/gate')
        ->and($writes)->toBe([1, 2]);

    $run->approve()->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
        ->and($run->awaiting_node)->toBe('each/2/gate')
        ->and($writes)->toBe([1, 2, 3]);

    $run->approve()->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED)
        ->and($writes)->toBe([1, 2, 3])
        ->and($run->outputs['end']['count'])->toBe(3)
        ->and($run->outputs['end']['results'])->toHaveCount(3)
        ->and($run->outputs)->not->toHaveKey('each/0/write')
        ->and($run->node_outputs)->toHaveKey('each/0/write');
})->with(['single', 'per_node']);

it('settles item failures as a distinct partial outcome without retrying successful items', function (string $driver): void {
    config()->set('fancy-flow.queue.driver', $driver);

    $seen = [];
    app(ExecutorRegistry::class)->bind('iteration_worker', function (ExecutionContext $ctx) use (&$seen): array {
        $item = $ctx->input();
        $seen[] = $item;
        if ($item === 2) {
            throw new RuntimeException('item rejected');
        }

        return ['value' => $item * 10];
    });

    $run = FancyFlow::dispatch(
        [
            '$schema' => Workflow::SCHEMA_URL,
            'version' => 1,
            'graph' => [
                'nodes' => [
                    ['id' => 'start', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                    ['id' => 'each', 'kind' => 'for_each', 'position' => ['x' => 0, 'y' => 1], 'config' => ['source' => '{{ in.items }}']],
                    ['id' => 'work', 'kind' => 'iteration_worker', 'position' => ['x' => 0, 'y' => 2], 'config' => []],
                    ['id' => 'end', 'kind' => 'output', 'position' => ['x' => 0, 'y' => 3], 'config' => []],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'start', 'target' => 'each'],
                    ['id' => 'e2', 'source' => 'each', 'target' => 'work', 'sourceHandle' => 'item'],
                    ['id' => 'e3', 'source' => 'each', 'target' => 'end', 'sourceHandle' => 'done'],
                ],
            ],
        ],
        ['start' => ['items' => [1, 2, 3]]],
    )->refresh();

    expect($run->status)->toBe(WorkflowRun::PARTIAL)
        ->and($run->isTerminal())->toBeTrue()
        ->and($run->error)->toContain('completed with 1 failed item')
        ->and($run->outputs['end']['failures'])->toHaveCount(1)
        ->and($run->outputs['end']['results'])->toBe([
            ['work' => ['value' => 10]],
            null,
            ['work' => ['value' => 30]],
        ])
        ->and($seen)->toBe([1, 2, 3]);
})->with(['single', 'per_node']);

it('qualifies a subflow gate per item and refuses an ambiguous legacy bare approval', function (): void {
    $child = Workflow::import(
        [
            '$schema' => Workflow::SCHEMA_URL,
            'version' => 1,
            'graph' => [
                'nodes' => [
                    ['id' => 'gate', 'kind' => 'human_approval', 'position' => ['x' => 0, 'y' => 0], 'config' => ['title' => 'Approve item']],
                ],
                'edges' => [],
            ],
        ],
        lenient: true,
        registry: app(\FancyFlow\NodeKindRegistry::class),
    )->graph;

    Capabilities::setWorkflowResolver(new class($child) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $child) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $ref === 'approval-child' ? $this->child : null;
        }
    });

    try {
        $run = FancyFlow::dispatch(
            [
                '$schema' => Workflow::SCHEMA_URL,
                'version' => 1,
                'graph' => [
                    'nodes' => [
                        ['id' => 'start', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                        ['id' => 'each', 'kind' => 'for_each', 'position' => ['x' => 0, 'y' => 1], 'config' => ['source' => '{{ in.items }}']],
                        ['id' => 'call', 'kind' => 'subflow', 'position' => ['x' => 0, 'y' => 2], 'config' => ['workflow' => 'approval-child']],
                        ['id' => 'end', 'kind' => 'output', 'position' => ['x' => 0, 'y' => 3], 'config' => []],
                    ],
                    'edges' => [
                        ['id' => 'e1', 'source' => 'start', 'target' => 'each'],
                        ['id' => 'e2', 'source' => 'each', 'target' => 'call', 'sourceHandle' => 'item'],
                        ['id' => 'e3', 'source' => 'each', 'target' => 'end', 'sourceHandle' => 'done'],
                    ],
                ],
            ],
            ['start' => ['items' => [1, 2]]],
        )->refresh();

        expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
            ->and($run->awaiting_node)->toBe('each/0/call/gate');

        // Simulate a pre-qualified-era stored answer. Two item occurrences make
        // the bare key ambiguous, so it must satisfy neither one.
        $run->forceFill([
            'approvals' => ['gate' => true],
            'status' => WorkflowRun::PENDING,
            'awaiting_node' => null,
            'awaiting_kind' => null,
            'awaiting_detail' => null,
        ])->save();
        RunWorkflowJob::enqueue($run);
        $run->refresh();

        expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
            ->and($run->awaiting_node)->toBe('each/0/call/gate');

        $run->approve()->refresh();
        expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
            ->and($run->awaiting_node)->toBe('each/1/call/gate');

        $run->approve()->refresh();
        expect($run->status)->toBe(WorkflowRun::COMPLETED);
    } finally {
        Capabilities::reset();
    }
});
