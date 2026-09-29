<?php

declare(strict_types=1);

use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/*
 * A cancelled run must not be resurrected by work already in flight.
 *
 * fancy-flow-php#27, reported from production: an operator clicked cancel, the
 * run finished anyway, and the output arrived a little later. The host had
 * added its own `cancelled` status and deleted the run's queued jobs, but the
 * node already executing finished and dispatched `AdvanceWorkflowJob`, which
 * did not recognise the status, `forceFill`ed it back to `running`, and carried
 * on to completion.
 *
 * The defect worth fixing is not "cancelled was missing from a list". It is
 * that the guard was a BLACKLIST — terminal-or-skipped — and everything else,
 * including a state invented by a host, was promoted back to running. A
 * blacklist has to be right about every state that will ever exist. The
 * whitelist (`canAdvance()`) only has to be right about ours, and an unknown
 * status now STOPS a run rather than restarting it.
 *
 * So the second test below is the one that generalises: it uses a status this
 * package has never heard of.
 */

function cancelTestRun(string $key, string $status): WorkflowRun
{
    $run = WorkflowRun::create([
        'run_key' => $key,
        'status' => $status,
        'schema' => [
            'nodes' => [
                ['id' => 'a', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]],
                ['id' => 'b', 'kind' => 'transform', 'position' => ['x' => 1, 'y' => 0]],
            ],
            'edges' => [['id' => 'e1', 'source' => 'a', 'target' => 'b']],
        ],
    ]);

    // `a` is already done: the run has real work behind it, which is what makes
    // resurrection expensive rather than merely untidy.
    WorkflowRunNode::create([
        'run_key' => $key,
        'node_id' => 'a',
        'status' => WorkflowRunNode::COMPLETED,
        'owner' => 'owner-1',
        'attempts' => 1,
    ]);

    return $run;
}

it('does not resurrect a run cancelled while a node was in flight', function () {
    $run = cancelTestRun('run_cancelled_inflight', WorkflowRun::RUNNING);

    expect($run->cancel('operator clicked cancel'))->toBeTrue();

    // The in-flight node has just finished and dispatched this, exactly as it
    // does in production. It must decline.
    app()->call([new AdvanceWorkflowJob($run->run_key), 'handle']);

    expect($run->fresh()->status)->toBe(WorkflowRun::CANCELLED)
        ->and($run->fresh()->outputs)->toBeNull();
});

it('does not promote a status it has never heard of back to running', function () {
    // The generalisation the reporter asked for: any host state, not just ours.
    // Before the fix this read `running` again and the run carried on.
    $run = cancelTestRun('run_unknown_status', WorkflowRun::RUNNING);
    $run->forceFill(['status' => 'archived_by_host'])->save();

    app()->call([new AdvanceWorkflowJob($run->run_key), 'handle']);

    expect($run->fresh()->status)->toBe('archived_by_host');
});

it('counts a cancelled run as terminal', function () {
    $run = cancelTestRun('run_cancelled_terminal', WorkflowRun::RUNNING);
    $run->cancel();

    expect($run->fresh()->isTerminal())->toBeTrue()
        ->and($run->fresh()->canAdvance())->toBeFalse();
});

it('refuses to cancel a run that already finished', function () {
    // Cancelling a completed run would rewrite history and lose its outputs.
    $run = cancelTestRun('run_already_done', WorkflowRun::COMPLETED);

    expect($run->cancel())->toBeFalse()
        ->and($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
});

it('still advances a run that is merely waiting for a person', function () {
    // The whitelist has to let the resumable states through, or a human gate
    // becomes a dead end. A test that only proved things STOP would pass
    // against a guard that stopped everything.
    $run = cancelTestRun('run_awaiting_ok', WorkflowRun::AWAITING_INPUT);

    expect($run->canAdvance())->toBeTrue();
});
