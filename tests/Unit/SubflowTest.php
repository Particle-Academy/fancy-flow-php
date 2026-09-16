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

// ---------------------------------------------------------------------------
// A PAUSE IS NOT A FAILURE (#21)
// ---------------------------------------------------------------------------

it('lets a human gate one level down still decode at the top', function (): void {
    // Until 0.57.0 every unsuccessful child run was wrapped as
    // `subflow "x" failed: <reason>`, and `Pause::decode()` is prefix-anchored
    // -- so a gate inside a subflow produced a string that no longer decoded.
    // The durable layer read a FAILED run rather than one parked on a person,
    // the gate became unresumable, and retry policy counted someone's pending
    // decision as a fault.
    //
    // Asserted through the ENGINE rather than by calling the executor, because
    // the defect is in what reaches the TOP of a run, and an executor-level
    // assertion would have passed throughout.
    $child = ffGraph([ffNode('gate', 'hostGate')]);
    $executors = \FancyFlow\Registry\Builtin::executors()
        ->bind('hostGate', fn (ExecutionContext $ctx) => $ctx->pauseForHuman('approval', ['title' => 'Approve item']));
    $executors->bind('subflow', new SubflowExecutor(resolver: mapResolver(['child' => $child])));

    $result = (new \FancyFlow\Engine\FlowRunner(connRegistryForSubflow()))->run(
        ffGraph([ffNode('sf', 'subflow', ['workflow' => 'child'])]),
        $executors,
    );

    expect($result->ok)->toBeFalse();

    // The assertion that carries the weight: it DECODES. Never assert on the
    // text -- the reason is verbatim by contract, and asserting its shape is
    // how a decorating change passes a suite that was meant to stop it.
    $pause = \FancyFlow\Runtime\Pause::decode((string) $result->error);
    expect($pause)->not->toBeNull();
    expect($pause->nodeId)->toBe('gate');
    expect($pause->awaiting)->toBe('approval');
});

it('still names the subflow when the child genuinely FAILS', function (): void {
    // The other half. The `subflow "x" failed:` prefix is real context for a
    // real failure and must not be lost while fixing the pause -- otherwise a
    // child error arrives at the top with nothing saying which child.
    $child = ffGraph([ffNode('boom', 'hostBoom')]);
    $executors = \FancyFlow\Registry\Builtin::executors()
        ->bind('hostBoom', fn (ExecutionContext $ctx) => $ctx->abort('the child exploded'));
    $executors->bind('subflow', new SubflowExecutor(resolver: mapResolver(['child' => $child])));

    $result = (new \FancyFlow\Engine\FlowRunner(connRegistryForSubflow()))->run(
        ffGraph([ffNode('sf', 'subflow', ['workflow' => 'child'])]),
        $executors,
    );

    expect($result->ok)->toBeFalse();
    expect((string) $result->error)->toContain('subflow "child" failed:');
    expect((string) $result->error)->toContain('the child exploded');
    expect(\FancyFlow\Runtime\Pause::decode((string) $result->error))->toBeNull();
});

function connRegistryForSubflow(): \FancyFlow\NodeKindRegistry
{
    return \FancyFlow\Registry\Builtin::register(new \FancyFlow\NodeKindRegistry(), withStructural: true);
}
