<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Laravel\Events\NodeMessage;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Jobs\RunNodeJob;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Testing\QueuePump;
use FancyFlow\Workflow;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

// ── harness ────────────────────────────────────────────────────────────────

function pnSchema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function pnNode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

function pnEdge(string $id, string $from, string $to, ?string $sourceHandle = null): array
{
    $edge = ['id' => $id, 'source' => $from, 'target' => $to];
    if ($sourceHandle !== null) {
        $edge['sourceHandle'] = $sourceHandle;
    }

    return $edge;
}

/** Persist a run without queueing it, so a test owns the run key up front. */
function pnRun(array $schema, array $inputs = [], array $extra = []): WorkflowRun
{
    $run = new WorkflowRun();
    $run->forceFill(array_merge([
        'run_key' => 'run_pn_'.bin2hex(random_bytes(5)),
        'status' => WorkflowRun::PENDING,
        'schema' => $schema,
        'initial_inputs' => $inputs,
    ], $extra))->save();

    return $run;
}

/**
 * Register a node kind whose executor records every id it runs, in order.
 *
 * The recording is the point: these are timing bugs, so what has to be asserted
 * is WHICH nodes executed and how often — a final output looks identical whether
 * a node ran once or three times.
 */
function pnRecorder(string $kind = 'rec', array $extra = []): void
{
    FancyFlow::extend(
        $kind,
        function (ExecutionContext $ctx): mixed {
            PnLog::$ran[] = $ctx->node->id;

            return $ctx->option('value', $ctx->node->id);
        },
        array_merge([
            'name' => $kind, 'category' => 'logic', 'label' => 'Recorder',
            'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
        ], $extra),
    );
}

final class PnLog
{
    /** @var list<string> node ids, in execution order */
    public static array $ran = [];

    public static ?string $runKey = null;

    /** @var array<class-string,int> how far pnPump() has drained each job class */
    public static array $cursor = [];

    public static function reset(): void
    {
        self::$ran = [];
        self::$runKey = null;
        self::$cursor = [];
    }

    /** How many times a node executed. */
    public static function count(string $nodeId): int
    {
        return count(array_filter(self::$ran, static fn (string $id) => $id === $nodeId));
    }
}

/**
 * Run the faked queue by hand, one job at a time.
 *
 * `sync` runs the whole advance → node → advance chain inline, which is what
 * most tests want but makes "the worker died here" impossible to express. With
 * the queue faked, this drains it in the order a worker would — pending node
 * jobs, then the advances they queued — and stops after `$maxNodes` node jobs.
 * Stopping IS the kill: the run is left exactly as a worker would leave it.
 *
 * @return int how many node jobs were executed
 */
function pnPump(int $maxNodes = PHP_INT_MAX): int
{
    // Delegates to the SHIPPED utility. Consumers were being told to copy this
    // function out of our test suite, which is a poor answer for the durable
    // layer's primary testing technique -- so it moved to src/ and this suite
    // now exercises the same code a consumer gets.
    return QueuePump::drain($maxNodes);
}

beforeEach(function () {
    PnLog::reset();
    QueuePump::reset();
});

// ── the shape of a per-node run ────────────────────────────────────────────

it('runs a graph as one job per node', function () {
    pnRecorder();

    $run = FancyFlow::dispatch(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('a', 'rec'), pnNode('b', 'rec'), pnNode('o', 'output')],
            [pnEdge('e1', 't', 'a'), pnEdge('e2', 'a', 'b'), pnEdge('e3', 'b', 'o')],
        ),
        ['t' => ['n' => 1]],
    );

    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect(PnLog::$ran)->toBe(['a', 'b']);

    // Every node has its own settled row — the per-node checkpoint.
    $rows = $run->nodes()->orderBy('id')->get();
    expect($rows->pluck('status', 'node_id')->all())->toBe([
        't' => WorkflowRunNode::COMPLETED,
        'a' => WorkflowRunNode::COMPLETED,
        'b' => WorkflowRunNode::COMPLETED,
        'o' => WorkflowRunNode::COMPLETED,
    ]);
    expect($run->outputs)->toHaveKeys(['t', 'a', 'b', 'o']);
});

it('checkpoints each node as it finishes, not once when the graph returns', function () {
    pnRecorder();

    $run = FancyFlow::dispatch(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('a', 'rec', ['value' => 'A'])],
            [pnEdge('e1', 't', 'a')],
        ),
        ['t' => []],
    );

    $row = $run->nodes()->where('node_id', 'a')->first();

    // Output AND the ports it activated, written with the status transition —
    // the ports are what tell the next advance which edges went live.
    expect($row->status)->toBe(WorkflowRunNode::COMPLETED);
    expect($row->output)->toBe('A');
    expect($row->ports)->toBe(['out']);
    expect($row->completed_at)->not->toBeNull();
});

it('ships with one job per node as the default', function () {
    // 0.10 shipped this driver behind a flag that defaulted to `single`, which
    // meant an upgrade changed nothing: the durability defect stayed live for
    // everyone who did not know to opt in. A fix nobody receives is not a fix.
    //
    // Asserted against the SHIPPED config, not the test environment's override,
    // because that is the file a consumer actually gets.
    $shipped = require dirname(__DIR__, 2).'/config/fancy-flow.php';

    expect($shipped['queue']['driver'])->toBe('per_node');

    // Drain stays OFF by default. It trades the per-node durability this whole
    // driver exists for against latency on short graphs, so it is opt-in even
    // now that the driver is not.
    expect($shipped['queue']['drain_limit'])->toBe(0);
});

it('still lets a host pin the single-job driver', function () {
    // The escape hatch has to keep working, or flipping the default is a
    // one-way door for anyone it surprises.
    config()->set('fancy-flow.queue.driver', 'single');

    expect(config('fancy-flow.queue.driver'))->toBe('single');
});

it('leaves the single-job driver completely alone', function () {
    config()->set('fancy-flow.queue.driver', 'single');
    pnRecorder();

    $run = FancyFlow::dispatch(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('a', 'rec')], [pnEdge('e1', 't', 'a')]),
        ['t' => []],
    );

    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    // No claims at all: the whole graph ran in one job, exactly as before.
    expect($run->nodes()->count())->toBe(0);
    expect($run->node_outputs)->toHaveKeys(['t', 'a']);
});

// ── a killed worker ────────────────────────────────────────────────────────
//
// The failure the whole change exists for. Under one job per graph, a worker
// killed mid-run never reached the single `node_outputs` write, so the retry
// re-ran every node that had already completed.

it('resumes a killed run with EXACTLY the un-run nodes remaining', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [
                pnNode('t', 'manual_trigger'),
                pnNode('a', 'rec'), pnNode('b', 'rec'), pnNode('c', 'rec'), pnNode('d', 'rec'),
            ],
            [pnEdge('e1', 't', 'a'), pnEdge('e2', 'a', 'b'), pnEdge('e3', 'b', 'c'), pnEdge('e4', 'c', 'd')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);

    // Three node jobs, then the worker dies. `t` + `a` + `b` are done; nothing
    // else has run.
    pnPump(maxNodes: 3);

    expect(PnLog::$ran)->toBe(['a', 'b']);
    expect($run->fresh()->nodes()->pluck('status', 'node_id')->all())->toBe([
        't' => WorkflowRunNode::COMPLETED,
        'a' => WorkflowRunNode::COMPLETED,
        'b' => WorkflowRunNode::COMPLETED,
    ]);

    // The retry. Every completed node has a checkpoint of its own, so resuming
    // is not "start again from the last whole-graph write".
    AdvanceWorkflowJob::dispatch($run->run_key);
    pnPump();

    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);

    // The assertion that matters: the completed SET, not the final output. Each
    // node executed exactly once across both attempts.
    expect(PnLog::$ran)->toBe(['a', 'b', 'c', 'd']);
});

// ── the claim ──────────────────────────────────────────────────────────────

it('runs a node ONCE when two workers reach it at the same time', function () {
    // A genuine overlap, not two sequential calls: the executor re-enters the
    // job for its OWN node while the first worker is still inside it. That is
    // the interleaving the claim has to survive — a second worker arriving
    // after the first has started and before it has finished.
    FancyFlow::extend('reentrant', function (ExecutionContext $ctx): mixed {
        PnLog::$ran[] = $ctx->node->id;

        if (count(PnLog::$ran) === 1) {
            app()->call([new RunNodeJob((string) PnLog::$runKey, $ctx->node->id), 'handle']);
        }

        return 'once';
    }, ['name' => 'reentrant', 'category' => 'logic', 'label' => 'Reentrant',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']]]);

    $run = pnRun(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('x', 'reentrant')], [pnEdge('e1', 't', 'x')]),
        ['t' => []],
    );
    PnLog::$runKey = $run->run_key;

    RunWorkflowJob::enqueue($run);

    expect(PnLog::count('x'))->toBe(1);
    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->nodes()->where('node_id', 'x')->first()->attempts)->toBe(1);
});

it('treats a lost claim as a no-op, not an error', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('a', 'rec')], [pnEdge('e1', 't', 'a')]),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    expect(PnLog::count('a'))->toBe(1);

    // A duplicate dispatch arrives late — a different worker, a different token.
    // It must not execute the node again, and it must not throw.
    app()->call([new RunNodeJob($run->run_key, 'a'), 'handle']);

    expect(PnLog::count('a'))->toBe(1);
    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
});

// ── retries, per node ──────────────────────────────────────────────────────

it('gives an unsafe-to-replay node exactly one attempt, whatever tries says', function () {
    config()->set('fancy-flow.queue.tries', 5);

    FancyFlow::extend('open_pr', PnBoom::class, [
        'name' => 'open_pr', 'category' => 'io', 'label' => 'Open PR',
        'sideEffects' => 'unsafe-to-replay',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);
    pnRecorder();

    Queue::fake();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('pr', 'open_pr'), pnNode('a', 'rec')],
            [pnEdge('e1', 't', 'pr'), pnEdge('e2', 't', 'a')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump(maxNodes: 1); // just the trigger, so both successors are queued

    $tries = Queue::pushed(RunNodeJob::class)
        ->mapWithKeys(fn (RunNodeJob $job) => [$job->nodeId => $job->tries])
        ->all();

    // The node that opens a pull request gets one attempt. Its replay-safe
    // sibling in the SAME run gets the configured five — which is the thing a
    // single run-level `tries` could never express.
    expect($tries['pr'])->toBe(1);
    expect($tries['a'])->toBe(5);
});

it('fails the run without retrying when an unsafe-to-replay node throws', function () {
    config()->set('fancy-flow.queue.tries', 5);

    FancyFlow::extend('open_pr', PnBoom::class, [
        'name' => 'open_pr', 'category' => 'io', 'label' => 'Open PR',
        'sideEffects' => 'unsafe-to-replay',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);

    $run = pnRun(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('pr', 'open_pr')], [pnEdge('e1', 't', 'pr')]),
        ['t' => []],
    );

    try {
        RunWorkflowJob::enqueue($run); // sync surfaces the retry throw
    } catch (Throwable) {
        // expected under the sync queue
    }

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::FAILED);
    expect($run->error)->toContain('boom');

    $node = $run->nodes()->where('node_id', 'pr')->first();
    expect($node->status)->toBe(WorkflowRunNode::FAILED);
    expect($node->attempts)->toBe(1); // never re-claimed

    // The nodes that DID complete keep their checkpoints, so a re-dispatch
    // resumes rather than replaying them.
    expect($run->node_outputs)->toHaveKey('t');
});

it('honours a per-kind tries override', function () {
    config()->set('fancy-flow.queue.tries', 1);
    config()->set('fancy-flow.queue.node_tries', ['rec' => 4]);
    pnRecorder();

    Queue::fake();

    $run = pnRun(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('a', 'rec')], [pnEdge('e1', 't', 'a')]),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump(maxNodes: 1);

    $job = Queue::pushed(RunNodeJob::class)->first(fn (RunNodeJob $j) => $j->nodeId === 'a');
    expect($job->tries)->toBe(4);
});

// ── fan-out and fan-in ─────────────────────────────────────────────────────

it('dispatches a job per branch on fan-out, and runs the fan-in once after both', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [
                pnNode('t', 'manual_trigger'),
                pnNode('left', 'rec'), pnNode('right', 'rec'),
                pnNode('join', 'rec'),
            ],
            [
                pnEdge('e1', 't', 'left'), pnEdge('e2', 't', 'right'),
                pnEdge('e3', 'left', 'join'), pnEdge('e4', 'right', 'join'),
            ],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump(maxNodes: 1); // the trigger only

    // Real fan-out: two independent jobs, which is two workers, not a
    // sequential walk inside one process.
    $queued = Queue::pushed(RunNodeJob::class)->map(fn (RunNodeJob $j) => $j->nodeId)->values()->all();
    expect($queued)->toBe(['t', 'left', 'right']);

    pnPump();

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED);

    // The fan-in ran once, and only after both of its inputs.
    expect(PnLog::count('join'))->toBe(1);
    expect(array_search('join', PnLog::$ran, true))
        ->toBeGreaterThan(max(array_search('left', PnLog::$ran, true), array_search('right', PnLog::$ran, true)));
});

it('records a dead branch as skipped and never dispatches a job for it', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [
                pnNode('t', 'manual_trigger'),
                pnNode('br', 'branch', ['condition' => '{{ $json.go }}']),
                pnNode('yes', 'rec'), pnNode('no', 'rec'),
            ],
            [
                pnEdge('e1', 't', 'br'),
                pnEdge('e2', 'br', 'yes', 'true'),
                pnEdge('e3', 'br', 'no', 'false'),
            ],
        ),
        ['t' => ['go' => false]],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect(PnLog::$ran)->toBe(['no']);

    expect($run->nodes()->where('node_id', 'yes')->first()->status)->toBe(WorkflowRunNode::SKIPPED);

    // Never even queued — a skip is decided by the frontier, not paid for with
    // a round trip and a job that no-ops.
    $queued = Queue::pushed(RunNodeJob::class)->map(fn (RunNodeJob $j) => $j->nodeId)->all();
    expect($queued)->not->toContain('yes');

    // And a skipped node contributes no output, so it cannot light a branch on
    // resume.
    expect($run->outputs)->not->toHaveKey('yes');
});

it('never dispatches a job for a note annotation', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('sticky', 'note'), pnNode('a', 'rec')],
            [pnEdge('e1', 't', 'a')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->nodes()->where('node_id', 'sticky')->first()->status)->toBe(WorkflowRunNode::SKIPPED);
    expect(Queue::pushed(RunNodeJob::class)->map(fn (RunNodeJob $j) => $j->nodeId)->all())->not->toContain('sticky');
});

// ── human waits ────────────────────────────────────────────────────────────

it('parks on a human_approval node and resumes on approve()', function () {
    $schema = pnSchema(
        [pnNode('t', 'manual_trigger'), pnNode('ap', 'human_approval'), pnNode('o', 'output')],
        [pnEdge('e1', 't', 'ap'), pnEdge('e2', 'ap', 'o', 'approved')],
    );

    $run = FancyFlow::dispatch($schema, ['t' => ['release' => '1.0']]);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($run->awaiting_node)->toBe('ap');
    expect($run->nodes()->where('node_id', 'ap')->first()->status)->toBe(WorkflowRunNode::PAUSED);

    $run->approve();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->outputs)->toHaveKey('o');
    // The paused claim was released so the node could consume the decision.
    expect($run->nodes()->where('node_id', 'ap')->first()->status)->toBe(WorkflowRunNode::COMPLETED);
});

it('parks on a third-party wait and resumes with the submitted payload', function () {
    FancyFlow::extend('countersign', PnCountersign::class, [
        'name' => 'countersign', 'category' => 'human', 'label' => 'Countersign',
        'pausesForHuman' => 'signature',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);

    $run = FancyFlow::dispatch(pnSchema(
        [pnNode('t', 'manual_trigger'), pnNode('c', 'countersign', ['document' => 'nda.pdf']), pnNode('o', 'output')],
        [pnEdge('e1', 't', 'c'), pnEdge('e2', 'c', 'o')],
    ));

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_HUMAN);
    expect($run->awaitingKind())->toBe('signature');
    expect($run->awaiting_detail)->toBe(['document' => 'nda.pdf']);

    $run->submitHuman('c', ['signedBy' => 'ada']);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->outputs['o'])->toBe(['signedBy' => 'ada']);
});

it('settles once when a run pauses, and once again when it finishes', function () {
    Event::fake([WorkflowSettled::class]);

    $run = FancyFlow::dispatch(pnSchema(
        [pnNode('t', 'manual_trigger'), pnNode('ap', 'human_approval'), pnNode('o', 'output')],
        [pnEdge('e1', 't', 'ap'), pnEdge('e2', 'ap', 'o', 'approved')],
    ), ['t' => []]);

    Event::assertDispatchedTimes(WorkflowSettled::class, 1);
    Event::assertDispatched(WorkflowSettled::class, fn (WorkflowSettled $e) => $e->outcome === WorkflowSettled::AWAITING_APPROVAL);

    $run->refresh()->approve();

    Event::assertDispatchedTimes(WorkflowSettled::class, 2);
    Event::assertDispatched(WorkflowSettled::class, fn (WorkflowSettled $e) => $e->outcome === WorkflowSettled::COMPLETED);
});

// ── structural failures ────────────────────────────────────────────────────

it('fails a cyclic graph before a single node runs', function () {
    pnRecorder();

    $run = pnRun(pnSchema(
        [pnNode('a', 'rec'), pnNode('b', 'rec')],
        [pnEdge('e1', 'a', 'b'), pnEdge('e2', 'b', 'a')],
    ));

    RunWorkflowJob::enqueue($run);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::FAILED);
    expect($run->error)->toBe('Cycle detected in flow graph — aborting.');
    expect(PnLog::$ran)->toBe([]);       // nothing executed, as on the engine
    expect($run->nodes()->count())->toBe(0);
});

it('fails the run when bookkeeping itself gives up, rather than stalling it', function () {
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema([pnNode('t', 'manual_trigger'), pnNode('a', 'rec')], [pnEdge('e1', 't', 'a')]),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump(maxNodes: 1);

    // Every step of this driver is kicked off by the previous one, so an advance
    // that dies for good is the end of the run whether or not anyone records it.
    (new AdvanceWorkflowJob($run->run_key))->failed(new RuntimeException('database went away'));

    $run->refresh();
    expect($run->status)->toBe(WorkflowRun::FAILED);
    expect($run->error)->toBe('database went away');
});

it('stops the run at the next node once the run-level timeout has passed', function () {
    config()->set('fancy-flow.timeout_ms', 0);
    pnRecorder();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('a', 'rec'), pnNode('b', 'rec')],
            [pnEdge('e1', 't', 'a'), pnEdge('e2', 'a', 'b')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::FAILED);
    expect($run->error)->toContain('timed out');
    // The deadline is checked BETWEEN nodes, exactly as the engine checks its
    // own — a node in flight is not interrupted, so at least one ran.
    expect(count(PnLog::$ran))->toBeLessThan(2);
});

// ── upgrading in place ─────────────────────────────────────────────────────

it('adopts a checkpoint written by the single-job driver instead of replaying it', function () {
    pnRecorder();

    // A run parked under 0.9: its progress lives in the node_outputs blob, and
    // there are no claim rows at all. Re-running `a` is the exact bug the
    // upgrade is meant to fix, so the upgrade had better not cause it.
    $run = pnRun(
        pnSchema(
            [pnNode('a', 'rec'), pnNode('b', 'rec'), pnNode('o', 'output')],
            [pnEdge('e1', 'a', 'b'), pnEdge('e2', 'b', 'o')],
        ),
        [],
        ['node_outputs' => ['a' => 'from the old driver']],
    );

    RunWorkflowJob::enqueue($run);
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect(PnLog::$ran)->toBe(['b']);                      // `a` was NOT re-run
    expect($run->outputs['a'])->toBe('from the old driver'); // its output survived
    expect($run->nodes()->where('node_id', 'a')->first()->status)->toBe(WorkflowRunNode::COMPLETED);
});

// ── cohorts ────────────────────────────────────────────────────────────────

it('checks a cohort guard once at the start, not before every node', function () {
    pnRecorder();
    config()->set('fancy-flow.guards', ['counting' => PnCountingGuard::class]);
    PnCountingGuard::$calls = 0;

    FancyFlow::dispatchCohort(
        [pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('a', 'rec'), pnNode('b', 'rec')],
            [pnEdge('e1', 't', 'a'), pnEdge('e2', 'a', 'b')],
        )],
        ['t' => []],
        guard: ['name' => 'counting'],
    );

    // Re-evaluating per node would let a mid-run change skip a half-executed
    // workflow — a worse outcome than either answer to the question.
    expect(PnCountingGuard::$calls)->toBe(1);
    expect(PnLog::$ran)->toBe(['a', 'b']);
});

it('does not re-ask the guard when a run that parked on its first node resumes', function () {
    config()->set('fancy-flow.guards', ['counting' => PnCountingGuard::class]);
    PnCountingGuard::$calls = 0;

    // The awkward case: the run pauses on node one, so releasing that claim
    // leaves NO rows at all. "Are there rows yet" would read that as a fresh
    // run and ask the guard a second time, mid-workflow.
    $runs = FancyFlow::dispatchCohort(
        [pnSchema(
            [pnNode('ap', 'human_approval'), pnNode('o', 'output')],
            [pnEdge('e1', 'ap', 'o', 'approved')],
        )],
        [],
        guard: ['name' => 'counting'],
    );

    $run = $runs[0]->refresh();
    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect(PnCountingGuard::$calls)->toBe(1);

    $run->approve();

    expect($run->refresh()->status)->toBe(WorkflowRun::COMPLETED);
    expect(PnCountingGuard::$calls)->toBe(1);
});

// ── drain ──────────────────────────────────────────────────────────────────

it('collapses a linear chain into one job when draining', function () {
    config()->set('fancy-flow.queue.drain_limit', 10);
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('a', 'rec'), pnNode('b', 'rec'), pnNode('c', 'rec')],
            [pnEdge('e1', 't', 'a'), pnEdge('e2', 'a', 'b'), pnEdge('e3', 'b', 'c')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
    expect(PnLog::$ran)->toBe(['a', 'b', 'c']);

    // Four nodes, one node job. The durability is unchanged — every node still
    // checkpoints as it finishes — what is saved is three queue round trips.
    expect(Queue::pushed(RunNodeJob::class)->count())->toBe(1);
});

it('refuses to drain across a human wait or an unsafe-to-replay node', function () {
    config()->set('fancy-flow.queue.drain_limit', 10);
    FancyFlow::extend('open_pr', function (ExecutionContext $ctx): mixed {
        PnLog::$ran[] = $ctx->node->id;

        return 'pr';
    }, [
        'name' => 'open_pr', 'category' => 'io', 'label' => 'Open PR',
        'sideEffects' => 'unsafe-to-replay',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);

    Queue::fake();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('pr', 'open_pr'), pnNode('ap', 'human_approval')],
            [pnEdge('e1', 't', 'pr'), pnEdge('e2', 'pr', 'ap')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    // Three separate jobs: the trigger would not drain into the PR node, and the
    // PR node would not drain into a node that parks the run.
    expect(Queue::pushed(RunNodeJob::class)->map(fn (RunNodeJob $j) => $j->nodeId)->values()->all())
        ->toBe(['t', 'pr', 'ap']);
    expect($run->fresh()->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
});

it('refuses to drain a fan-out, so parallelism is never traded for latency', function () {
    config()->set('fancy-flow.queue.drain_limit', 10);
    pnRecorder();
    Queue::fake();

    $run = pnRun(
        pnSchema(
            [pnNode('t', 'manual_trigger'), pnNode('left', 'rec'), pnNode('right', 'rec')],
            [pnEdge('e1', 't', 'left'), pnEdge('e2', 't', 'right')],
        ),
        ['t' => []],
    );

    RunWorkflowJob::enqueue($run);
    pnPump();

    expect(Queue::pushed(RunNodeJob::class)->map(fn (RunNodeJob $j) => $j->nodeId)->values()->all())
        ->toBe(['t', 'left', 'right']);
    expect($run->fresh()->status)->toBe(WorkflowRun::COMPLETED);
});

// ── fixtures ───────────────────────────────────────────────────────────────

/** Always throws — a node that fails, deterministically. */
final class PnBoom implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        throw new RuntimeException('boom');
    }
}

/** A third-party human wait this package knows nothing about. */
final class PnCountersign implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        $signed = $ctx->inputs['values'] ?? null;

        if ($signed === null) {
            $ctx->pauseForHuman('signature', ['document' => $ctx->option('document', 'contract.pdf')]);
        }

        return $signed;
    }
}

/** Counts how often a cohort guard is consulted. */
final class PnCountingGuard implements \FancyFlow\Contracts\TriggerGuard
{
    public static int $calls = 0;

    public function passes(array $args, array $context): bool
    {
        self::$calls++;

        return true;
    }

    public function reason(array $args, array $context): string
    {
        return 'never skipped';
    }
}

it('delivers NodeMessage on the DURABLE path, which is the one a Laravel app runs', function () {
    // The reported defect was that `startingMsg` / `stoppingMsg` never reach a
    // Laravel consumer. Fixing the bridge's `default => null` arm makes them
    // arrive on the in-process path -- but the report was specifically about the
    // DURABLE path, where the caller is `RunNodeJob` and a host never gets to
    // pass its own `$onEvent`.
    //
    // So this test exists rather than an inference. A fix verified only on the
    // path nobody uses is the failure mode being fixed, wearing a green tick.
    //
    // It passes because `FancyFlowManager::run()` applies the bridge ITSELF
    // rather than leaving it to callers -- every path is wrapped, including
    // `GraphReplay`'s, whose own `$onEvent` the bridge chains rather than
    // replaces.
    Event::fake([NodeMessage::class]);

    FancyFlow::dispatch(
        pnSchema([[
            'id' => 't',
            'kind' => 'manual_trigger',
            'position' => ['x' => 0, 'y' => 0],
            'config' => [],
            'startingMsg' => 'Consulting the team',
            'stoppingMsg' => 'Team consulted',
        ]]),
        ['t' => ['n' => 1]],
    );

    Event::assertDispatched(
        NodeMessage::class,
        fn (NodeMessage $e) => $e->nodeId === 't' && $e->phase === 'start' && $e->message === 'Consulting the team',
    );
    Event::assertDispatched(
        NodeMessage::class,
        fn (NodeMessage $e) => $e->nodeId === 't' && $e->phase === 'end' && $e->message === 'Team consulted',
    );
});

it('runs only the entry point that fired, on the per-node driver too', function () {
    // The reported defect, on the driver the reporter actually runs. The
    // FlowRunner gate alone is not enough here: the per-node driver decides
    // readiness ITSELF in `Frontier::compute`, so without the same gate there it
    // would dispatch a job for a trigger the engine then refuses to run, and the
    // run would never settle.
    //
    // Graph: two triggers, a private branch each, converging on one output.
    $run = FancyFlow::dispatch(
        pnSchema(
            [
                pnNode('t1', 'manual_trigger'),
                pnNode('t2', 'manual_trigger'),
                pnNode('a', 'transform', ['expression' => '{{ $json.n }}']),
                pnNode('b', 'transform', ['expression' => '{{ $json.n }}']),
                pnNode('m', 'output'),
            ],
            [
                pnEdge('e1', 't1', 'a'),
                pnEdge('e2', 't2', 'b'),
                pnEdge('e3', 'a', 'm'),
                pnEdge('e4', 'b', 'm'),
            ],
        ),
        ['t1' => ['n' => 1], 't2' => ['n' => 2]],
        entryNodes: ['t1'],
    );

    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);

    // `t2` was not named, so it is inactive; `b` is reachable only from `t2` and
    // therefore never becomes ready. `m` still runs -- it has one active inbound
    // edge, which has always been enough.
    $ran = array_keys($run->outputs ?? []);
    sort($ran);
    expect($ran)->toBe(['a', 'm', 't1']);
});

it('still runs every entry point when entryNodes is unset', function () {
    // The compatibility guard, and the reason `null` and `[]` are different
    // things. Every durable run recorded before the column existed reads back
    // null, and must behave exactly as it did.
    $run = FancyFlow::dispatch(
        pnSchema(
            [pnNode('t1', 'manual_trigger'), pnNode('t2', 'manual_trigger'), pnNode('m', 'output')],
            [pnEdge('e1', 't1', 'm'), pnEdge('e2', 't2', 'm')],
        ),
        ['t1' => ['n' => 1], 't2' => ['n' => 2]],
    );

    $run->refresh();

    $ran = array_keys($run->outputs ?? []);
    sort($ran);
    expect($ran)->toBe(['m', 't1', 't2']);
});
