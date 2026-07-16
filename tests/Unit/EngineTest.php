<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;

function runGraph($graph, ExecutorRegistry $executors, ?RunOptions $options = null): array
{
    $result = (new FlowRunner())->run($graph, $executors, options: $options);

    return [$result, $result->outputs];
}

it('executes nodes in topological order', function () {
    $seen = [];
    $graph = ffGraph(
        [ffNode('a', 'x'), ffNode('b', 'x'), ffNode('c', 'x')],
        [ffEdge('e1', 'a', 'b'), ffEdge('e2', 'b', 'c')],
    );
    $executors = (new ExecutorRegistry())->bind('x', function (ExecutionContext $c) use (&$seen) {
        $seen[] = $c->node->id;

        return $c->node->id;
    });

    [$result] = runGraph($graph, $executors);

    expect($result->ok)->toBeTrue();
    expect($seen)->toBe(['a', 'b', 'c']);
});

it('seeds entry nodes from initialInputs', function () {
    $graph = ffGraph([ffNode('t', 'trigger')], []);
    $executors = (new ExecutorRegistry())->bind('trigger', fn (ExecutionContext $c) => $c->input('payload'));

    [$result] = runGraph($graph, $executors, new RunOptions(initialInputs: ['t' => ['payload' => ['hi' => 1]]]));

    expect($result->output('t'))->toBe(['hi' => 1]);
});

it('runs a merge point after a decision routes down one branch (#1)', function () {
    // d -> (true) a -> m ; d -> (false) b -> m ; m has distinct ports a/b
    $graph = ffGraph(
        [
            ffNode('d', 'decide', outputs: ['true', 'false']),
            ffNode('a', 'echo'),
            ffNode('b', 'echo'),
            ffNode('m', 'merge', inputs: ['a', 'b']),
        ],
        [
            ffEdge('e1', 'd', 'a', sourceHandle: 'true'),
            ffEdge('e2', 'd', 'b', sourceHandle: 'false'),
            ffEdge('e3', 'a', 'm', targetHandle: 'a'),
            ffEdge('e4', 'b', 'm', targetHandle: 'b'),
        ],
    );
    $executors = (new ExecutorRegistry())
        ->bind('decide', fn (ExecutionContext $c) => Port::branch('true', 'V'))
        ->bind('echo', fn (ExecutionContext $c) => ['echoed' => $c->node->id, 'in' => $c->input('in')])
        ->bind('merge', fn (ExecutionContext $c) => array_values(array_filter($c->inputs, fn ($v) => $v !== null)));

    [$result, $outputs] = runGraph($graph, $executors);

    expect($result->ok)->toBeTrue();
    expect($outputs)->toHaveKey('a');          // active branch ran
    expect($outputs)->not->toHaveKey('b');     // dead branch skipped
    expect($result->output('m'))->toBe([['echoed' => 'a', 'in' => 'V']]); // merge got the live value only
});

it('detects cycles and aborts', function () {
    $graph = ffGraph(
        [ffNode('a', 'x'), ffNode('b', 'x')],
        [ffEdge('e1', 'a', 'b'), ffEdge('e2', 'b', 'a')],
    );
    $executors = (new ExecutorRegistry())->bind('x', fn (ExecutionContext $c) => 1);

    [$result] = runGraph($graph, $executors);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('Cycle detected');
});

it('errors when no executor is registered for a kind', function () {
    $graph = ffGraph([ffNode('a', 'mystery')], []);

    [$result] = runGraph($graph, new ExecutorRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->error)->toBe('No executor registered for kind=mystery');
});

it('resolves executors by node id, then kind, then * fallback', function () {
    $graph = ffGraph([ffNode('a', 'k'), ffNode('b', 'k'), ffNode('c', 'other')], []);
    $executors = (new ExecutorRegistry())
        ->bindNode('a', fn (ExecutionContext $c) => 'by-id')
        ->bind('k', fn (ExecutionContext $c) => 'by-kind')
        ->bind('*', fn (ExecutionContext $c) => 'by-fallback');

    [, $outputs] = runGraph($graph, $executors);

    expect($outputs)->toBe(['a' => 'by-id', 'b' => 'by-kind', 'c' => 'by-fallback']);
});

it('publishes a plain result on every declared output port', function () {
    $graph = ffGraph(
        [ffNode('a', 'x', outputs: ['p1', 'p2']), ffNode('b', 'x'), ffNode('c', 'x')],
        [ffEdge('e1', 'a', 'b', sourceHandle: 'p1'), ffEdge('e2', 'a', 'c', sourceHandle: 'p2')],
    );
    $executors = (new ExecutorRegistry())
        ->bind('x', fn (ExecutionContext $c) => $c->node->id === 'a' ? 'SHARED' : $c->input('in'));

    [, $outputs] = runGraph($graph, $executors);

    expect($outputs['b'])->toBe('SHARED');
    expect($outputs['c'])->toBe('SHARED');
});

it('supports Port::only to fire a single named port', function () {
    $graph = ffGraph(
        [ffNode('a', 'x', outputs: ['p1', 'p2']), ffNode('b', 'x'), ffNode('c', 'x')],
        [ffEdge('e1', 'a', 'b', sourceHandle: 'p1'), ffEdge('e2', 'a', 'c', sourceHandle: 'p2')],
    );
    $executors = (new ExecutorRegistry())
        ->bind('x', fn (ExecutionContext $c) => $c->node->id === 'a' ? Port::only('p1', 'ONLY') : $c->input('in'));

    [, $outputs] = runGraph($graph, $executors);

    expect($outputs['b'])->toBe('ONLY');
    expect($outputs)->not->toHaveKey('c'); // p2 never fired
});

it('captures an executor abort() as a run error', function () {
    $graph = ffGraph([ffNode('a', 'x')], []);
    $executors = (new ExecutorRegistry())->bind('x', fn (ExecutionContext $c) => $c->abort('nope'));

    [$result] = runGraph($graph, $executors);

    expect($result->ok)->toBeFalse();
    expect($result->error)->toBe('nope');
});

it('resumes without re-executing completed nodes', function () {
    $graph = ffGraph(
        [ffNode('a', 'x'), ffNode('b', 'x'), ffNode('c', 'x')],
        [ffEdge('e1', 'a', 'b'), ffEdge('e2', 'b', 'c')],
    );
    $calls = [];
    $executors = (new ExecutorRegistry())->bind('x', function (ExecutionContext $c) use (&$calls) {
        $calls[] = $c->node->id;
        if (in_array($c->node->id, ['a', 'b'], true)) {
            throw new \RuntimeException('completed node should not re-run: '.$c->node->id);
        }

        return ['ran' => $c->node->id, 'in' => $c->input('in')];
    });

    [$result] = runGraph($graph, $executors, new RunOptions(resumeOutputs: [
        'a' => ['ran' => 'a'],
        'b' => ['ran' => 'b', 'carried' => true],
    ]));

    expect($result->ok)->toBeTrue();
    expect($calls)->toBe(['c']);                                       // only the unfinished node ran
    expect($result->output('a'))->toBe(['ran' => 'a']);               // completed node republished
    expect($result->output('c')['in'])->toBe(['ran' => 'b', 'carried' => true]); // c saw b's republished output
});

it('times out a run between nodes', function () {
    $graph = ffGraph(
        [ffNode('a', 'slow'), ffNode('b', 'slow')],
        [ffEdge('e1', 'a', 'b')],
    );
    $executors = (new ExecutorRegistry())->bind('slow', function (ExecutionContext $c) {
        usleep(15_000);

        return 1;
    });

    [$result] = runGraph($graph, $executors, new RunOptions(timeoutMs: 5));

    expect($result->ok)->toBeFalse();
    expect($result->error)->toContain('timed out');
});

it('streams a well-formed event sequence', function () {
    $graph = ffGraph([ffNode('a', 'x')], []);
    $types = [];
    (new FlowRunner())->run(
        $graph,
        (new ExecutorRegistry())->bind('x', fn (ExecutionContext $c) => 1),
        function (RunEvent $e) use (&$types) { $types[] = $e->type; },
    );

    expect($types[0])->toBe('run-start');
    expect(end($types))->toBe('run-end');
    expect($types)->toContain('node-status');
    expect($types)->toContain('node-output');
});
