<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Workflow;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/**
 * A human gate inside a SUBFLOW must be answerable, not merely visible (#22).
 *
 * 0.57.0 fixed the first half: the pause reaches the top decodable, so the run
 * parks on the gate with its detail intact instead of being recorded as a
 * failure. MOIC took it and found the second half missing — the ask arrives and
 * the answer goes nowhere.
 *
 * The cause is that `awaiting_node` is UNQUALIFIED. It names `gate`, which
 * exists only in the CHILD graph; the parent's own schema holds the subflow node
 * and nothing else. So there is no node in this run to re-enter, and a
 * submission recorded against that bare id is never read by anyone.
 *
 * Together those two are the shape this estate keeps finding: each half looks
 * right on its own, and the pair loses a person's decision silently. Pressing
 * the button does nothing, which is arguably worse for them than the failure it
 * replaced.
 */
function sgSchema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function sgNode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

beforeEach(function (): void {
    // The child graph, resolved by name. Its gate is the one a person answers.
    $child = Workflow::import(
        sgSchema(
            [
                sgNode('c_in', 'manual_trigger'),
                sgNode('gate', 'human_approval', ['title' => 'Approve the child']),
                sgNode('c_out', 'output'),
            ],
            [
                ['id' => 'ce1', 'source' => 'c_in', 'target' => 'gate'],
                ['id' => 'ce2', 'source' => 'gate', 'target' => 'c_out', 'sourceHandle' => 'approved'],
            ],
        ),
        lenient: true,
        registry: app(\FancyFlow\NodeKindRegistry::class),
    )->graph;

    Capabilities::setWorkflowResolver(new class($child) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $child) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $ref === 'child' ? $this->child : null;
        }
    });
});

afterEach(fn () => Capabilities::reset());

it('parks on a gate that lives inside a subflow, with its detail intact', function (): void {
    // The half 0.57.0 fixed. Asserted here so a regression is caught by THIS
    // file rather than by a consumer.
    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('call', 'subflow', ['workflow' => 'child']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'call'],
                ['id' => 'e2', 'source' => 'call', 'target' => 'end'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    );
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($run->awaiting_detail)->not->toBeNull();
});

it('RESUMES when that gate is answered', function (): void {
    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('call', 'subflow', ['workflow' => 'child']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'call'],
                ['id' => 'e2', 'source' => 'call', 'target' => 'end'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    );
    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);

    // The whole point: a person answers, and the run finishes.
    $run->approve();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
});

// ---------------------------------------------------------------------------
// The `user_input` shape — MOIC's reported one (status awaiting_input)
// ---------------------------------------------------------------------------

function sgInputChild(): FlowGraph
{
    return Workflow::import(
        sgSchema(
            [
                sgNode('c_in', 'manual_trigger'),
                sgNode('gate', 'user_input', ['title' => 'Approve the child', 'fields' => [['name' => 'ok']]]),
                sgNode('c_out', 'output'),
            ],
            [
                ['id' => 'ce1', 'source' => 'c_in', 'target' => 'gate'],
                ['id' => 'ce2', 'source' => 'gate', 'target' => 'c_out'],
            ],
        ),
        lenient: true,
        registry: app(\FancyFlow\NodeKindRegistry::class),
    )->graph;
}

it('resumes a user_input gate inside a subflow when the form is submitted', function (): void {
    $child = sgInputChild();
    Capabilities::setWorkflowResolver(new class($child) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $child) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $ref === 'child' ? $this->child : null;
        }
    });

    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('call', 'subflow', ['workflow' => 'child']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'call'],
                ['id' => 'e2', 'source' => 'call', 'target' => 'end'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    );
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_INPUT);
    expect($run->awaiting_node)->toBe('gate');

    // The half MOIC reports missing: the answer must reach the child's gate.
    $run->submitInput(values: ['ok' => true]);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
});

it('renders a form for a gate that lives inside a subflow', function (): void {
    // MOIC's consumer-side half: awaitingForm() looks `awaiting_node` up in the
    // RUN's own schema, which holds the parent's nodes only -- so a child gate
    // finds nothing and a person sees an empty approval.
    $child = sgInputChild();
    Capabilities::setWorkflowResolver(new class($child) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $child) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $ref === 'child' ? $this->child : null;
        }
    });

    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('call', 'subflow', ['workflow' => 'child']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'call'],
                ['id' => 'e2', 'source' => 'call', 'target' => 'end'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    );
    $run->refresh();

    expect($run->awaitingForm())->not->toBeNull();
});
