<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\RunResult;

it('runs the item lane once per item and publishes collected results on done', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
            ffNode('score', 'score'),
            ffNode('summary', 'summary'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'score', sourceHandle: 'item'),
            ffEdge('e3', 'each', 'summary', sourceHandle: 'done'),
        ],
    );

    $seen = [];
    $keys = [];
    $executors = Builtin::executors()
        ->bind('source', fn () => ['items' => [2, 4, 6]])
        ->bind('score', function (ExecutionContext $ctx) use (&$seen, &$keys): array {
            $seen[] = $ctx->input();
            $keys[] = $ctx->run?->stepKey($ctx->node->id);

            return ['score' => $ctx->input() * 10];
        })
        ->bind('summary', fn (ExecutionContext $ctx) => $ctx->input());

    $result = (new FlowRunner())->run(
        $graph,
        $executors,
        options: new RunOptions(run: new RunIdentity('iteration-test')),
    );

    expect($result->ok)->toBeTrue()
        ->and($seen)->toBe([2, 4, 6])
        ->and($keys)->toBe([
            'iteration-test:each#0/score',
            'iteration-test:each#1/score',
            'iteration-test:each#2/score',
        ])
        ->and($result->output('summary'))->toBe([
            'items' => [2, 4, 6],
            'results' => [
                ['score' => ['score' => 20]],
                ['score' => ['score' => 40]],
                ['score' => ['score' => 60]],
            ],
            'failures' => [],
            'count' => 3,
        ]);
});

it('finishes an empty list once without running the item lane', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
            ffNode('body', 'body'),
            ffNode('summary', 'summary'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
            ffEdge('e3', 'each', 'summary', sourceHandle: 'done'),
        ],
    );

    $bodyCalls = 0;
    $result = (new FlowRunner())->run(
        $graph,
        Builtin::executors()
            ->bind('source', fn () => ['items' => []])
            ->bind('body', function () use (&$bodyCalls): void {
                $bodyCalls++;
            })
            ->bind('summary', fn (ExecutionContext $ctx) => $ctx->input()),
    );

    expect($result->ok)->toBeTrue()
        ->and($bodyCalls)->toBe(0)
        ->and($result->output('summary'))->toBe(['items' => [], 'results' => [], 'failures' => [], 'count' => 0]);
});

it('keeps whole-list delivery as an explicit collect escape hatch', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}', 'mode' => 'collect'], ['item', 'done']),
            ffNode('body', 'body'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
        ],
    );

    $result = (new FlowRunner())->run(
        $graph,
        Builtin::executors()
            ->bind('source', fn () => ['items' => [1, 2]])
            ->bind('body', fn (ExecutionContext $ctx) => $ctx->input()),
    );

    expect($result->ok)->toBeTrue()
        ->and($result->output('body'))->toBe(['items' => [1, 2], 'count' => 2]);
});

it('continues after an item fails and reports the failure beside ordered results', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
            ffNode('body', 'body'),
            ffNode('summary', 'summary'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
            ffEdge('e3', 'each', 'summary', sourceHandle: 'done'),
        ],
    );

    $seen = [];
    $result = (new FlowRunner())->run(
        $graph,
        Builtin::executors()
            ->bind('source', fn () => ['items' => [1, 2, 3]])
            ->bind('body', function (ExecutionContext $ctx) use (&$seen): array {
                $item = $ctx->input();
                $seen[] = $item;
                if ($item === 2) {
                    throw new RuntimeException('bad item');
                }

                return ['value' => $item * 10];
            })
            ->bind('summary', fn (ExecutionContext $ctx) => $ctx->input()),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->outcome)->toBe(RunResult::PARTIAL)
        ->and($result->error)->toBe('for_each "each" completed with 1 failed item(s)')
        ->and($seen)->toBe([1, 2, 3])
        ->and($result->output('summary'))->toBe([
            'items' => [1, 2, 3],
            'results' => [
                ['body' => ['value' => 10]],
                null,
                ['body' => ['value' => 30]],
            ],
            'failures' => [[
                'index' => 1,
                'item' => 2,
                'error' => 'node (body, body): bad item',
            ]],
            'count' => 3,
        ]);
});

it('escapes slash-bearing checkpoint segments and resumes the intended nested node', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each/segment', 'for_each', ['source' => '{{ in.items }}'], ['item']),
            ffNode('body/segment', 'body'),
            ffNode('gate/segment', 'gate'),
        ],
        [
            ffEdge('e1', 'source', 'each/segment'),
            ffEdge('e2', 'each/segment', 'body/segment', sourceHandle: 'item'),
            ffEdge('e3', 'body/segment', 'gate/segment'),
        ],
    );

    $checkpoints = [];
    $bodyCalls = 0;
    $mayContinue = false;
    $executors = Builtin::executors()
        ->bind('source', fn () => ['items' => [7]])
        ->bind('body', function (ExecutionContext $ctx) use (&$bodyCalls): array {
            $bodyCalls++;

            return ['item' => $ctx->input()];
        })
        ->bind('gate', function (ExecutionContext $ctx) use (&$mayContinue): array {
            if (! $mayContinue) {
                $ctx->pauseForHuman('approval');
            }

            return ['approved' => true];
        });

    $first = (new FlowRunner())->run(
        $graph,
        $executors,
        onEvent: function (RunEvent $event) use (&$checkpoints): void {
            if ($event->type === RunEvent::NODE_CHECKPOINT) {
                $checkpoints[(string) $event->nodeId] = $event->value;
            }
        },
    );

    expect($first->ok)->toBeFalse()
        ->and(Pause::decode((string) $first->error)?->nodeId)->toBe('each%2Fsegment/0/gate%2Fsegment')
        ->and(array_keys($checkpoints))->toBe(['each%2Fsegment/0/body%2Fsegment'])
        ->and($bodyCalls)->toBe(1);

    $mayContinue = true;
    $second = (new FlowRunner())->run(
        $graph,
        $executors,
        options: new RunOptions(resumeOutputs: $checkpoints),
    );

    expect($second->ok)->toBeTrue()
        ->and($bodyCalls)->toBe(1);
});

it('fails before execution when a top-level id impersonates a nested checkpoint address', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
            ffNode('body', 'body'),
            ffNode('each/0/body', 'summary'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
            ffEdge('e3', 'each', 'each/0/body', sourceHandle: 'done'),
        ],
    );

    $sourceCalls = 0;
    $result = (new FlowRunner())->run(
        $graph,
        Builtin::executors()
            ->bind('source', function () use (&$sourceCalls): array {
                $sourceCalls++;

                return ['items' => [1]];
            })
            ->bind('body', fn (ExecutionContext $ctx) => $ctx->input())
            ->bind('summary', fn (ExecutionContext $ctx) => $ctx->input()),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('aliases the checkpoint namespace')
        ->and($sourceCalls)->toBe(0);
});

it('refuses to start a lane whose item count exceeds its configured cap', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}', 'maxItems' => 2], ['item', 'done']),
            ffNode('body', 'body'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
        ],
    );

    $bodyCalls = 0;
    $result = (new FlowRunner())->run(
        $graph,
        Builtin::executors()
            ->bind('source', fn () => ['items' => [1, 2, 3]])
            ->bind('body', function () use (&$bodyCalls): void {
                $bodyCalls++;
            }),
    );

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('3 items exceeds its maxItems cap of 2')
        ->and($bodyCalls)->toBe(0);
});

it('resumes an iteration without repeating body nodes that already completed', function () {
    $graph = ffGraph(
        [
            ffNode('source', 'source'),
            ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
            ffNode('write', 'write'),
            ffNode('gate', 'gate'),
        ],
        [
            ffEdge('e1', 'source', 'each'),
            ffEdge('e2', 'each', 'write', sourceHandle: 'item'),
            ffEdge('e3', 'write', 'gate'),
        ],
    );

    $writes = [];
    $mayContinue = false;
    $events = [];
    $executors = Builtin::executors()
        ->bind('source', fn () => ['items' => [1, 2, 3]])
        ->bind('write', function (ExecutionContext $ctx) use (&$writes): array {
            $writes[] = $ctx->input();

            return ['item' => $ctx->input()];
        })
        ->bind('gate', function (ExecutionContext $ctx) use (&$mayContinue): array {
            if ($ctx->input()['item'] === 2 && ! $mayContinue) {
                $ctx->pauseForHuman('approval', ['title' => 'Continue item 2']);
            }

            return ['accepted' => $ctx->input()['item']];
        });

    $first = (new FlowRunner())->run(
        $graph,
        $executors,
        onEvent: function (RunEvent $event) use (&$events): void {
            $events[] = $event;
        },
        options: new RunOptions(run: new RunIdentity('resume-test')),
    );

    expect($first->ok)->toBeFalse()
        ->and(Pause::decode((string) $first->error))->not->toBeNull()
        ->and($writes)->toBe([1, 2]);

    $checkpoints = [];
    foreach ($events as $event) {
        if ($event->type === RunEvent::NODE_CHECKPOINT) {
            $checkpoints[(string) $event->nodeId] = $event->value;
        }
    }

    expect(array_keys($checkpoints))->toBe([
        'each/0/write',
        'each/0/gate',
        'each/1/write',
    ]);

    $mayContinue = true;
    $second = (new FlowRunner())->run(
        $graph,
        $executors,
        options: new RunOptions(
            resumeOutputs: $checkpoints,
            run: new RunIdentity('resume-test'),
        ),
    );

    expect($second->ok)->toBeTrue()
        ->and($writes)->toBe([1, 2, 3]);
});
