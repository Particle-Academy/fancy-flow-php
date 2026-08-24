<?php

declare(strict_types=1);

use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Laravel\Runs\NodeClaims;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/*
 * A pause writes TWO rows, and they must land together.
 *
 * `RunNodeJob` records a human gate in two separate statements:
 *
 *   NodeClaims::pause(...)   -> workflow_run_nodes.status = PAUSED
 *   $run->forceFill([...])   -> workflow_runs.status = AWAITING_INPUT
 *                               + awaiting_node / awaiting_kind / awaiting_detail
 *
 * with no transaction around them. A worker that dies between the two -- OOM,
 * a deploy, a dropped connection -- leaves the node SETTLED as paused while the
 * run still reads `running`.
 *
 * That state is unrecoverable rather than merely wrong:
 *
 *   - nothing re-dispatches the node, because its claim is settled;
 *   - a human answering is REJECTED, because the run is not parked on it;
 *   - and the awaiting_* fields exist ONLY on the run row, so unlike a
 *     completed node -- whose ports the Frontier recomputes from claim rows --
 *     there is nothing left to reconstruct the gate from.
 *
 * That asymmetry is the point. `NodeClaims::complete()` can afford to be a
 * separate write because its consequences are derivable; `pause()` cannot,
 * because its consequences are not.
 *
 * Found chasing a consumer's report of runs stuck at `running` where
 * `awaiting_input` was expected. Their case turned out to be worker latency
 * under load and they retracted it -- but they declined to rule out a genuine
 * pause bug, and this is one. Narrow window, permanent consequence, no
 * detection: exactly the profile the durable layer exists to eliminate, whose
 * whole premise is that a lost race is a NO-OP.
 */

it('rolls the node claim back when the run status write fails', function () {
    $run = WorkflowRun::create([
        'run_key' => 'run_pause_atomicity',
        'status' => WorkflowRun::RUNNING,
        'schema' => ['nodes' => [], 'edges' => []],
    ]);

    WorkflowRunNode::create([
        'run_key' => $run->run_key,
        'node_id' => 'gate',
        'status' => WorkflowRunNode::CLAIMED,
        'owner' => 'owner-1',
        'attempts' => 1,
    ]);

    // Drive the PRODUCTION operation and make its second write fail, which is
    // what a crash between the two looks like from the database's side.
    //
    // An earlier version of this test wrapped the writes in its OWN
    // transaction and passed against the unfixed code -- proving that
    // transactions work, not that the package uses one. That is the shape of
    // regression test that passes either way and proves nothing.
    try {
        NodeClaims::pauseAndPark($run->run_key, 'gate', $run, [
            'status' => WorkflowRun::AWAITING_INPUT,
            'no_such_column' => 'boom',
        ]);
    } catch (Throwable) {
        // expected: the run write blows up part-way through the operation
    }

    $node = WorkflowRunNode::where('run_key', $run->run_key)->where('node_id', 'gate')->first();

    expect($node->status)->not->toBe(WorkflowRunNode::PAUSED)
        ->and($run->fresh()->status)->toBe(WorkflowRun::RUNNING);
});

it('records the pause and the run status as one unit', function () {
    // The positive half: when it does succeed, both halves are present. A fix
    // that achieved atomicity by writing neither would pass the test above.
    $run = WorkflowRun::create([
        'run_key' => 'run_pause_together',
        'status' => WorkflowRun::RUNNING,
        'schema' => ['nodes' => [], 'edges' => []],
    ]);

    WorkflowRunNode::create([
        'run_key' => $run->run_key,
        'node_id' => 'gate',
        'status' => WorkflowRunNode::CLAIMED,
        'owner' => 'owner-1',
        'attempts' => 1,
    ]);

    NodeClaims::pauseAndPark($run->run_key, 'gate', $run, [
        'status' => WorkflowRun::AWAITING_INPUT,
        'awaiting_node' => 'gate',
        'awaiting_kind' => 'input',
    ]);

    $node = WorkflowRunNode::where('run_key', $run->run_key)->where('node_id', 'gate')->first();

    expect($node->status)->toBe(WorkflowRunNode::PAUSED)
        ->and($run->fresh()->status)->toBe(WorkflowRun::AWAITING_INPUT)
        ->and($run->fresh()->awaiting_node)->toBe('gate');
});
