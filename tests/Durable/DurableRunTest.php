<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Workflow;

uses(\FancyFlow\Tests\Durable\DurableTestCase::class);

function dschema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function dnode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

it('dispatches a durable run to completion (sync queue)', function () {
    $run = FancyFlow::dispatch(
        dschema(
            [dnode('t', 'manual_trigger'), dnode('tf', 'transform', ['expression' => '{{ $json.n }}']), dnode('o', 'output')],
            [['id' => 'e1', 'source' => 't', 'target' => 'tf'], ['id' => 'e2', 'source' => 'tf', 'target' => 'o']],
        ),
        ['t' => ['n' => 21]],
    );

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->outputs['o'])->toBe(21);
    expect($run->node_outputs)->toHaveKeys(['t', 'tf', 'o']);
});

it('resumes from the checkpoint without re-executing completed nodes', function () {
    FancyFlow::extend('explode', ExplodingExecutor::class, ['name' => 'explode', 'category' => 'logic', 'label' => 'Explode']);

    // 'a' would throw if executed — but it is pre-checkpointed, so resume skips it.
    $run = new WorkflowRun();
    $run->forceFill([
        'run_key' => 'run_resume_'.bin2hex(random_bytes(4)),
        'status' => WorkflowRun::PENDING,
        'schema' => dschema(
            [dnode('a', 'explode'), dnode('b', 'transform', ['expression' => 'done']), dnode('o', 'output')],
            [['id' => 'e1', 'source' => 'a', 'target' => 'b'], ['id' => 'e2', 'source' => 'b', 'target' => 'o']],
        ),
        'initial_inputs' => [],
        'node_outputs' => ['a' => ['already' => 'ran']], // checkpoint from a prior attempt
    ])->save();

    \FancyFlow\Laravel\Jobs\RunWorkflowJob::enqueue($run);

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED); // 'a' never re-ran (it would have thrown)
    expect($run->outputs['o'])->toBe('done');
});

it('marks a genuinely failing run as failed and checkpoints the completed prefix', function () {
    FancyFlow::extend('explode', ExplodingExecutor::class, ['name' => 'explode', 'category' => 'logic', 'label' => 'Explode']);

    $run = new WorkflowRun();
    $run->forceFill([
        'run_key' => 'run_fail_'.bin2hex(random_bytes(4)),
        'status' => WorkflowRun::PENDING,
        'schema' => dschema(
            [dnode('t', 'manual_trigger'), dnode('boom', 'explode')],
            [['id' => 'e1', 'source' => 't', 'target' => 'boom']],
        ),
        'initial_inputs' => ['t' => ['x' => 1]],
    ])->save();

    // The sync queue re-throws a failed job (a real queue catches + retries it);
    // either way the job's failed() handler marks the run and checkpoints.
    try {
        \FancyFlow\Laravel\Jobs\RunWorkflowJob::enqueue($run);
    } catch (\FancyFlow\Laravel\Jobs\WorkflowRunFailed) {
        // expected under sync
    }

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::FAILED);
    expect($run->error)->toContain('boom');
    expect($run->node_outputs)->toHaveKey('t');   // the completed prefix is checkpointed for resume
    expect($run->node_outputs)->not->toHaveKey('boom');
});

it('pauses at a human_approval node, then resumes on approve()', function () {
    $schema = dschema(
        [
            dnode('t', 'manual_trigger'),
            dnode('ap', 'human_approval', ['title' => 'Ship it?']),
            dnode('o', 'output'),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'ap'], ['id' => 'e2', 'source' => 'ap', 'target' => 'o', 'sourceHandle' => 'approved']],
    );

    $run = FancyFlow::dispatch($schema, ['t' => ['release' => '1.0']]);
    $run->refresh();

    // Paused — awaiting a human decision, not failed.
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($run->awaiting_node)->toBe('ap');
    expect($run->outputs)->toBeNull();

    // Approve → the job re-runs (sync) and completes down the approved branch.
    $run->approve();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->outputs)->toHaveKey('o');
});

it('routes to the denied branch on deny()', function () {
    $schema = dschema(
        [
            dnode('t', 'manual_trigger'),
            dnode('ap', 'human_approval'),
            dnode('no', 'log', ['message' => 'denied']),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'ap'], ['id' => 'e2', 'source' => 'ap', 'target' => 'no', 'sourceHandle' => 'denied']],
    );

    $run = FancyFlow::dispatch($schema, ['t' => []]);
    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);

    $run->deny();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->outputs)->toHaveKey('no'); // the denied branch ran
});

it('pauses at a user_input node, then resumes with the submitted values', function () {
    $schema = dschema(
        [
            dnode('t', 'manual_trigger'),
            dnode('form', 'user_input', ['title' => 'Ship details', 'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']]]),
            dnode('o', 'output'),
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'form'], ['id' => 'e2', 'source' => 'form', 'target' => 'o']],
    );

    $run = FancyFlow::dispatch($schema, ['t' => []]);
    $run->refresh();

    // Paused awaiting the human form — not completed with empty values.
    expect($run->status)->toBe(WorkflowRun::AWAITING_INPUT);
    expect($run->awaiting_node)->toBe('form');
    expect($run->outputs)->toBeNull();

    // The host can render the paused node's form.
    expect($run->awaitingForm())->toMatchArray([
        'nodeId' => 'form',
        'title' => 'Ship details',
        'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']],
    ]);

    // Submit → the job re-runs (sync) and the values flow out of the node.
    $run->submitInput(values: ['note' => 'looks good']);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->submissions)->toBe(['form' => ['note' => 'looks good']]);
    expect($run->outputs['form'])->toBe(['note' => 'looks good']);
});

it('treats an empty submission as a real submission, not another pause', function () {
    $schema = dschema(
        [dnode('t', 'manual_trigger'), dnode('form', 'user_input'), dnode('o', 'output')],
        [['id' => 'e1', 'source' => 't', 'target' => 'form'], ['id' => 'e2', 'source' => 'form', 'target' => 'o']],
    );

    $run = FancyFlow::dispatch($schema, ['t' => []]);
    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_INPUT);

    $run->submitInput(values: []);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
});

it('falls back to the kind configSchema defaults when the node declares no form', function () {
    $schema = dschema(
        [dnode('t', 'manual_trigger'), dnode('form', 'user_input')],
        [['id' => 'e1', 'source' => 't', 'target' => 'form']],
    );

    $run = FancyFlow::dispatch($schema, ['t' => []]);
    $run->refresh();

    $form = $run->awaitingForm();
    expect($form['nodeId'])->toBe('form');
    // Defaults come from the `user_input` kind's configSchema.
    expect($form['title'])->toBe('Need your input');
    expect($form['fields'])->toBe([['key' => 'answer', 'label' => 'Your answer', 'type' => 'textarea']]);
});

it('exposes no form when the run is not awaiting input', function () {
    $schema = dschema([dnode('t', 'manual_trigger'), dnode('o', 'output')], [['id' => 'e1', 'source' => 't', 'target' => 'o']]);

    $run = FancyFlow::dispatch($schema, ['t' => []]);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->awaitingForm())->toBeNull();
});

/** An executor that always throws — stands in for a failing / not-yet-ready node. */
final class ExplodingExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        throw new \RuntimeException('boom');
    }
}
