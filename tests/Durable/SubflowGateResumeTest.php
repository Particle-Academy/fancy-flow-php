<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
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

it('refuses a legacy bare answer when sibling subflows make it ambiguous', function (): void {
    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('first', 'subflow', ['workflow' => 'child']),
                sgNode('second', 'subflow', ['workflow' => 'child']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'first'],
                ['id' => 'e2', 'source' => 'first', 'target' => 'second'],
                ['id' => 'e3', 'source' => 'second', 'target' => 'end'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    )->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
        ->and($run->awaiting_node)->toBe('first/gate');

    // Both child graphs contain `gate`. A pre-qualified-era answer cannot be
    // assigned safely, so it must satisfy neither occurrence.
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
        ->and($run->awaiting_node)->toBe('first/gate');

    $run->approve()->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL)
        ->and($run->awaiting_node)->toBe('second/gate');

    $run->approve()->refresh();
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
    expect($run->awaiting_node)->toBe('call/gate');

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

// ---------------------------------------------------------------------------
// A THIRD-PARTY pausing kind inside a subflow (#22, MOIC's actual shape)
// ---------------------------------------------------------------------------

/**
 * A host gate, exactly as a marketplace / `#[FlowNode]` kind writes one: it
 * pauses through the public contract and reads its answer back off its own
 * `values` INPUT PORT.
 *
 * That port is the only resume channel such a kind has —
 * `RunSetup::initialInputs()` says so in its own comment — because this package
 * cannot reach inside a third-party executor the way it can override its own
 * two human kinds.
 */
final class SgHostGate implements \FancyFlow\Contracts\NodeExecutor
{
    public function execute(\FancyFlow\Runtime\ExecutionContext $ctx): mixed
    {
        // THE SEAM (fancy-flow-php#22). A host kind asks whether ITS OWN answer
        // has arrived, keyed by address, so this resumes identically at the top
        // level, inside a subflow (`call/gate`) and per iteration
        // (`each/1/gate`). The engine no longer has to know this kind by name.
        $answer = $ctx->humanAnswer();
        $values = is_array($answer) ? ($answer['values'] ?? null) : null;

        // The legacy path stays as a fallback: an answer merged onto the
        // `values` port by RunSetup::initialInputs(). That map is keyed by bare
        // node id against the graph being RUN, which is why it reaches a
        // top-level node and never one inside a child graph.
        $values ??= $ctx->inputs['values'] ?? null;

        if ($values === null) {
            $ctx->pauseForHuman('input', ['kind' => 'review_approval', 'title' => 'Approve the child']);
        }

        $decision = is_array($values) ? (string) ($values['decision'] ?? '') : '';

        return \FancyFlow\Runtime\Port::branch($decision === 'approved' ? 'approved' : 'rejected', $values);
    }
}

it('resumes a THIRD-PARTY pausing kind at the top level', function (): void {
    // The baseline, and it passes: at the top level the recorded submission is
    // merged onto the node's `values` port by RunSetup::initialInputs(), which
    // is keyed by node id against the graph being run.
    app(\FancyFlow\ExecutorRegistry::class)->bind('sg_host_gate', new SgHostGate());

    $run = FancyFlow::dispatch(
        sgSchema(
            [
                sgNode('trigger', 'manual_trigger'),
                sgNode('gate', 'sg_host_gate', ['title' => 'Approve']),
                sgNode('end', 'output'),
            ],
            [
                ['id' => 'e1', 'source' => 'trigger', 'target' => 'gate'],
                ['id' => 'e2', 'source' => 'gate', 'target' => 'end', 'sourceHandle' => 'approved'],
            ],
        ),
        ['trigger' => ['deal' => 42]],
    );
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_INPUT);

    $run->submitInput(values: ['decision' => 'approved']);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
});

it('resumes a THIRD-PARTY pausing kind that lives inside a SUBFLOW', function (): void {
    // The reported bug. `initialInputs` is keyed by node id and handed to the
    // PARENT graph, whose nodes are trigger/call/end -- `gate` is not among
    // them, so the merged `values` port is never consumed. The subflow seeds
    // the child's ENTRY nodes from its own inputs and knows nothing of the
    // run's recorded answers, so the answer stops at the boundary.
    //
    // Our own two human kinds do NOT hit this: they resume on membership in the
    // run's recorded answers, through a durable override bound by KIND, which
    // survives into the child. So a builtin gate resumes one level down and a
    // third-party gate does not -- the divergence this row exists to name.
    app(\FancyFlow\ExecutorRegistry::class)->bind('sg_host_gate', new SgHostGate());

    $child = Workflow::import(
        sgSchema(
            [
                sgNode('c_in', 'manual_trigger'),
                sgNode('gate', 'sg_host_gate', ['title' => 'Approve the child']),
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
    // QUALIFIED since 0.59: a gate one level down parks at `call/gate`, not
    // `gate`. This assertion predates that and was asserting the old shape.
    expect($run->awaiting_node)->toBe('call/gate');

    $run->submitInput(values: ['decision' => 'approved']);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
});

/**
 * **The consumer's actual shape, end to end.**
 *
 * Neither test above covers it. `SubflowChildReplayTest` counts re-execution
 * around a `human_approval`; the `user_input` case above has nothing before the
 * gate to re-execute. The reported case has BOTH: a reusable Op whose form is
 * its standalone interface, called as a subflow, with real work ahead of the
 * form.
 *
 * Their owner's words for what they expected to exist:
 *
 * > *"did fany ever upgrade fancy flow so we can have subflows with user inputs
 * > that bubble up?"*
 *
 * Three properties at once, and the middle one is the one that was broken:
 * the form reaches the person, the work ahead of it happens ONCE across the
 * pause, and answering it finishes the run.
 */
it('runs a reusable Op with a form, called as a subflow, doing its pre-form work once', function (): void {
    $writes = 0;

    $child = Workflow::import(
        sgSchema(
            [
                sgNode('c_in', 'manual_trigger'),
                sgNode('writes', 'sgOpWriter'),
                sgNode('gate', 'user_input', ['title' => 'Solutions Map', 'fields' => [['name' => 'ok']]]),
                sgNode('c_out', 'output'),
            ],
            [
                ['id' => 'ce1', 'source' => 'c_in', 'target' => 'writes'],
                ['id' => 'ce2', 'source' => 'writes', 'target' => 'gate'],
                ['id' => 'ce3', 'source' => 'gate', 'target' => 'c_out'],
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

    app(\FancyFlow\ExecutorRegistry::class)->bind('sgOpWriter', function () use (&$writes) {
        $writes++;

        return ['wrote' => $writes];
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

    // 1. The form reached the person, from one level down.
    expect($run->status)->toBe(WorkflowRun::AWAITING_INPUT)
        ->and($run->awaitingForm())->not->toBeNull();
    expect($writes)->toBe(1, 'the work ahead of the form ran once');

    // 2. Answering it finishes the run.
    $run->submitInput(values: ['ok' => true]);
    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED);

    // 3. THE ONE THAT WAS BROKEN. A node that WRITES, ahead of a form, inside a
    //    reused Op, must not write again because someone took a minute to
    //    answer. 2 here is a duplicate row in a tenant's database.
    expect($writes)->toBe(1, 'pre-form work must not repeat when the parent resumes');
});

/**
 * The payload accessors return what the legacy `values` port carried.
 *
 * `humanAnswer()` returns the engine's RECORD — `['values' => …]` — while the
 * legacy `$ctx->inputs['values']` port carried the values themselves. The
 * 0.60.0 docblock and changelog both showed `return $answer;`, which is a
 * SILENT behaviour change for a migrating host: the keys it reads move one
 * level down, its decision field reads as absent, and the run COMPLETES having
 * skipped the step after the gate. Green, with no error anywhere.
 *
 * A consumer hit exactly that on 6 of 8 cases. These pin the shapes so the
 * documented migration and the code cannot drift apart again.
 */
it('hands back the form payload, not the record that wraps it', function (): void {
    $ctx = new \FancyFlow\Runtime\ExecutionContext(
        node: new \FancyFlow\Schema\FlowNode(id: 'gate', type: 'user_input'),
        inputs: [],
        emit: static fn () => null,
        humanAnswers: ['gate' => ['values' => ['decision' => 'approved', 'note' => 'ok']]],
    );

    expect($ctx->humanValues())->toBe(['decision' => 'approved', 'note' => 'ok'])
        ->and($ctx->humanAnswer())->toBe(['values' => ['decision' => 'approved', 'note' => 'ok']])
        ->and($ctx->humanApproved())->toBeNull();
});

it('distinguishes a REJECTION from silence', function (): void {
    $make = fn (array $answers) => new \FancyFlow\Runtime\ExecutionContext(
        node: new \FancyFlow\Schema\FlowNode(id: 'gate', type: 'human_approval'),
        inputs: [],
        emit: static fn () => null,
        humanAnswers: $answers,
    );

    // false is a DECISION. Branching on truthiness would read it as
    // un-answered and pause the node again, forever.
    expect($make(['gate' => ['approved' => false]])->humanApproved())->toBeFalse();
    expect($make(['gate' => ['approved' => true]])->humanApproved())->toBeTrue();
    expect($make([])->humanApproved())->toBeNull();

    // A form answer is not an approval, and must not be coerced into one.
    expect($make(['gate' => ['values' => ['a' => 1]]])->humanApproved())->toBeNull();
});
