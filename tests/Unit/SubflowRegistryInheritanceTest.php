<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Schema\FlowGraph;

/**
 * A subflow must run its child against THE SAME REGISTRY as its parent — issue #7.
 *
 * Reported by a consumer running a real graph: a child workflow containing a
 * host-registered kind failed with `No executor registered for kind=<host-kind>`,
 * while the identical graph run at top level succeeded. Same workflow, two
 * different behaviours depending on nesting depth.
 *
 * The cause, verified against source before fixing: `SubflowExecutor` resolved
 * the child's registry as `$this->executors ?? Builtin::executors($this->deps)`,
 * and `Builtin::executors()` constructs it as `new SubflowExecutor($deps)` —
 * never passing one, because the composed registry does not exist yet at that
 * point. So the child always fell back to the BARE builtins and lost:
 *
 *   - every host executor bound via `config('fancy-flow.executors')`,
 *   - the `agent` binding,
 *   - the `ContainerResolver` the parent was given.
 *
 * The sharpest case is not a missing kind but a REPLACED one. A host that
 * overrides `llm_call` to add tenancy, budgeting or token accounting gets its
 * own version in the parent and the package's inside the child — the same graph
 * billing two different ways depending on where it was invoked from. Nothing
 * warns, because an unregistered kind fails closed with no outputs, which is the
 * right default and exactly what makes this silent.
 *
 * The fix is inheritance by default: the runner hands the registry it is running
 * with to the ExecutionContext, so anything starting a nested run gets the
 * parent's registry without having to remember to.
 */

/** A resolver over a fixed ref → graph map; unknown refs resolve to null. */
function inheritResolver(array $graphs): WorkflowResolver
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

/** A one-node graph whose single node is a host-registered kind. */
function hostKindGraph(string $nodeId = 'c1'): FlowGraph
{
    return ffGraph([ffNode($nodeId, 'host_kind')]);
}

/** A parent graph whose single node is a subflow pointing at $ref. */
function subflowParent(string $ref = 'child'): FlowGraph
{
    return ffGraph([ffNode('sub', 'subflow', ['workflow' => $ref])]);
}

it('resolves a host kind inside the child, not only at top level', function () {
    $ran = [];
    $registry = Builtin::executors();
    $registry->bind('host_kind', function () use (&$ran) {
        $ran[] = 'host';

        return 'ok';
    });

    Capabilities::setWorkflowResolver(inheritResolver(['child' => hostKindGraph()]));

    // Control: the child graph run DIRECTLY succeeds. If this ever fails the
    // test is wrong about the registry rather than about nesting.
    $direct = (new FlowRunner())->run(hostKindGraph(), $registry);
    expect($direct->ok)->toBeTrue();
    expect($ran)->toBe(['host']);

    // The reported failure: the same graph, one level down.
    $ran = [];
    $nested = (new FlowRunner())->run(subflowParent(), $registry);

    expect($nested->ok)->toBeTrue('a host kind must resolve inside a subflow');
    expect($ran)->toBe(['host']);
});

it('gives the child the HOST\'s override of a kind, not the package\'s', function () {
    // The expensive case: a host that replaces a builtin to add tenancy or
    // budgeting must not silently get the package's version inside a child.
    $used = [];
    $registry = Builtin::executors();
    $registry->bind('host_kind', function () use (&$used) {
        $used[] = 'host version';

        return 1;
    });

    Capabilities::setWorkflowResolver(inheritResolver(['child' => hostKindGraph()]));
    (new FlowRunner())->run(subflowParent(), $registry);

    expect($used)->toBe(['host version']);
});

it('carries the registry down to a grandchild, not only one level', function () {
    // Depth is where a "pass it down once" fix quietly stops working.
    $ran = [];
    $registry = Builtin::executors();
    $registry->bind('host_kind', function () use (&$ran) {
        $ran[] = 'deep';

        return 1;
    });

    $middle = ffGraph([ffNode('m1', 'subflow', ['workflow' => 'grandchild'])]);

    Capabilities::setWorkflowResolver(inheritResolver([
        'child' => $middle,
        'grandchild' => hostKindGraph('g1'),
    ]));

    $result = (new FlowRunner())->run(subflowParent(), $registry);

    expect($result->ok)->toBeTrue();
    expect($ran)->toBe(['deep']);
});
