<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Exceptions\RunAborted;
use FancyFlow\Nodes\Structural\SubflowExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

afterEach(fn () => Capabilities::reset());

/** A resolver over a fixed map of ref → graph. */
function mapResolver(array $graphs): WorkflowResolver
{
    return new class($graphs) implements WorkflowResolver
    {
        public function __construct(private array $graphs) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $this->graphs[$ref] ?? null;
        }
    };
}

/** A one-node child graph whose `output` node echoes what it was seeded with. */
function childGraph(): FlowGraph
{
    return ffGraph([ffNode('child_out', '@particle-academy/output')]);
}

function subflowExec(array $config, array $inputs = [], ?array &$events = null, int $depth = 0, ?WorkflowResolver $resolver = null): mixed
{
    $captured = [];
    $result = (new SubflowExecutor(resolver: $resolver))->execute(new ExecutionContext(
        new FlowNode(id: 'sf', type: 'subflow', config: $config),
        $inputs,
        function (RunEvent $e) use (&$captured) { $captured[] = $e; },
        $depth,
    ));
    $events = $captured;

    return $result;
}

it('runs the resolved child workflow and returns its outputs on `out`', function () {
    Capabilities::setWorkflowResolver(mapResolver(['onboarding' => childGraph()]));

    $result = subflowExec(['workflow' => 'onboarding'], ['in' => ['user' => 'ada']]);

    expect($result['__port'])->toBe('out')
        ->and($result['value'])->toBe(['child_out' => ['user' => 'ada']]);
});

it('streams child progress as tagged log lines against the SUBFLOW node', function () {
    // A child's node ids mean nothing in the parent graph, so its events are
    // NOT re-emitted — they are rendered onto the parent's feed, attributed to
    // this node.
    Capabilities::setWorkflowResolver(mapResolver(['onboarding' => childGraph()]));

    subflowExec(['workflow' => 'onboarding', 'mode' => 'stream'], events: $events);

    $logs = array_values(array_filter($events, fn (RunEvent $e) => $e->type === RunEvent::LOG));
    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        expect($log->nodeId)->toBe('sf')
            ->and($log->message)->toStartWith('[onboarding] ');
    }

    $messages = array_map(fn (RunEvent $e) => $e->message, $logs);
    expect($messages)->toContain('[onboarding] child_out done')
        ->and($messages)->toContain('[onboarding] finished (ok)');

    // No child event leaks onto the parent feed under the child's own node id.
    $foreign = array_filter($events, fn (RunEvent $e) => $e->nodeId !== null && $e->nodeId !== 'sf');
    expect($foreign)->toBeEmpty();
});

it('emits on `stream` in stream mode and on every port in both mode', function () {
    Capabilities::setWorkflowResolver(mapResolver(['w' => childGraph()]));

    expect(subflowExec(['workflow' => 'w', 'mode' => 'stream'])['__port'])->toBe('stream');

    // `both` publishes on every declared port — no __port marker.
    expect(subflowExec(['workflow' => 'w', 'mode' => 'both']))->toBe(['child_out' => []]);
});

it('adds the stream port only when something streams', function () {
    $ports = fn (array $c) => array_map(fn ($p) => $p->id, SubflowExecutor::ports($c));

    expect($ports([]))->toBe(['out']);
    expect($ports(['mode' => 'output']))->toBe(['out']);
    expect($ports(['mode' => 'stream']))->toBe(['stream', 'out']);
    expect($ports(['mode' => 'both']))->toBe(['stream', 'out']);
});

it('names the offending reference when the depth limit is reached', function () {
    // "Maximum function nesting level" tells an author nothing about the
    // workflow they wired into itself.
    Capabilities::setWorkflowResolver(mapResolver(['loop' => childGraph()]));

    expect(fn () => subflowExec(['workflow' => 'loop', 'maxDepth' => 2], depth: 2))
        ->toThrow(RunAborted::class, 'subflow depth limit reached (2) at "loop"');
});

it('actually stops a self-referencing workflow instead of overflowing the stack', function () {
    // The child IS the parent: without the guard this recurses forever.
    $selfRef = ffGraph([ffNode('again', 'subflow', ['workflow' => 'loop', 'maxDepth' => 3])]);
    Capabilities::setWorkflowResolver(mapResolver(['loop' => $selfRef]));

    expect(fn () => subflowExec(['workflow' => 'loop', 'maxDepth' => 3]))
        ->toThrow(RunAborted::class, 'referencing itself');
});

it('aborts when the reference resolves to nothing', function () {
    Capabilities::setWorkflowResolver(mapResolver([]));

    expect(fn () => subflowExec(['workflow' => 'ghost']))
        ->toThrow(RunAborted::class, 'subflow could not resolve workflow "ghost"');
});

it('aborts when no workflow reference is configured', function () {
    Capabilities::setWorkflowResolver(mapResolver([]));

    expect(fn () => subflowExec([]))
        ->toThrow(RunAborted::class, 'subflow has no workflow reference configured');
});

it('aborts with an actionable message when no resolver is registered', function () {
    expect(fn () => subflowExec(['workflow' => 'onboarding']))
        ->toThrow(RunAborted::class, 'no workflow resolver registered');
});

it('honours an explicit input mapping over the pass-through default', function () {
    Capabilities::setWorkflowResolver(mapResolver(['w' => childGraph()]));

    $result = subflowExec([
        'workflow' => 'w',
        'inputs' => ['child_out' => ['seeded' => true]],
    ], ['in' => 'ignored']);

    expect($result['value'])->toBe(['child_out' => ['seeded' => true]]);
});

it('surfaces a child failure as a named parent abort', function () {
    // A child node with no executor bound fails the child run.
    Capabilities::setWorkflowResolver(mapResolver(['broken' => ffGraph([ffNode('x', 'no_such_kind')])]));

    expect(fn () => subflowExec(['workflow' => 'broken']))
        ->toThrow(RunAborted::class, 'subflow "broken" failed');
});
