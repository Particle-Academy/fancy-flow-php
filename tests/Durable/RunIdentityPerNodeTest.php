<?php

declare(strict_types=1);

use FancyFlow\Laravel\Events\HumanInputRequested;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Jobs\RunNodeJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Workflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/**
 * Run identity under the per-node driver, and the step-wise contract around it.
 *
 * Two things are asserted here that cannot be asserted anywhere else, because
 * only this driver has a per-node claim row:
 *
 *  1. the idempotency key a writing node derives is the SAME on a retry of that
 *     node and DIFFERENT for every other execution;
 *  2. a human gate holds no worker — the job that reaches it fires the request,
 *     checkpoints the pause, and RETURNS.
 */

// ── harness ────────────────────────────────────────────────────────────────

final class RiLog
{
    /** @var list<array{node:string,key:?string,attempt:?int,firstAttemptAt:?string}> */
    public static array $seen = [];

    /** @var array<string,int> node id -> how many times it should still fail */
    public static array $failFor = [];

    public static array $cursor = [];

    public static function reset(): void
    {
        self::$seen = [];
        self::$failFor = [];
        self::$cursor = [];
    }

    public static function keysFor(string $nodeId): array
    {
        return array_values(array_map(
            static fn (array $row) => $row['key'],
            array_filter(self::$seen, static fn (array $row) => $row['node'] === $nodeId),
        ));
    }
}

beforeEach(fn () => RiLog::reset());

function riSchema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function riNode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

function riEdge(string $id, string $from, string $to, ?string $handle = null): array
{
    $edge = ['id' => $id, 'source' => $from, 'target' => $to];
    if ($handle !== null) {
        $edge['sourceHandle'] = $handle;
    }

    return $edge;
}

/**
 * A node that records the idempotency key it WOULD send, and can be told to
 * fail its first N attempts.
 */
function riWriter(string $kind = 'writer', array $extra = []): void
{
    FancyFlow::extend(
        $kind,
        function (ExecutionContext $ctx): mixed {
            RiLog::$seen[] = [
                'node' => $ctx->node->id,
                'key' => $ctx->run?->stepKey($ctx->node->id),
                'attempt' => $ctx->run?->attempt,
                'firstAttemptAt' => $ctx->run?->firstAttemptAt,
            ];

            if ((RiLog::$failFor[$ctx->node->id] ?? 0) > 0) {
                RiLog::$failFor[$ctx->node->id]--;
                throw new RuntimeException('transient');
            }

            return ['charged' => true];
        },
        array_merge([
            'name' => $kind, 'category' => 'io', 'label' => 'Writer',
            'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
        ], $extra),
    );
}

// ── the key ────────────────────────────────────────────────────────────────

it('gives a node an idempotency key derived from the run', function () {
    riWriter();

    $run = FancyFlow::dispatch(riSchema([riNode('pay', 'writer')]));

    expect(RiLog::keysFor('pay'))->toBe([$run->run_key.':pay']);
});

/**
 * Retry one node the way Laravel does: the SAME job instance, handled again.
 *
 * `sync` does not retry, so a test that only dispatches proves nothing about a
 * retry. A released job comes back as the same object carrying the same `owner`
 * token, which is precisely what re-enters its own claim instead of losing the
 * race to itself — so re-handling one instance IS the retry, not an
 * approximation of it.
 *
 * @return int how many attempts were made
 */
function riRetryNode(WorkflowRun $run, string $nodeId, int $attempts, ?callable $between = null): int
{
    $job = new RunNodeJob($run->run_key, $nodeId, tries: $attempts);
    $made = 0;

    for ($i = 0; $i < $attempts; $i++) {
        if ($i > 0 && $between !== null) {
            $between($i);
        }

        $made++;
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            // The throw is what buys the node its retries. Swallow it here and
            // hand the same instance back, exactly as the queue would.
            continue;
        }
        break;
    }

    return $made;
}

/** Persist a run without queueing it, so a test owns the run key up front. */
function riRun(array $schema, array $inputs = []): WorkflowRun
{
    $run = new WorkflowRun();
    $run->forceFill([
        'run_key' => 'run_ri_'.bin2hex(random_bytes(5)),
        'status' => WorkflowRun::PENDING,
        'schema' => $schema,
        'initial_inputs' => $inputs,
    ])->save();

    return $run;
}

it('sends the SAME key on a retry of the same node', function () {
    // The money case. A retry that changes the key charges the card twice; a
    // retry that reuses it is deduplicated by the provider into the first
    // charge, which is the entire point of carrying an identity.
    Queue::fake();
    riWriter();
    RiLog::$failFor['pay'] = 1;

    $run = riRun(riSchema([riNode('pay', 'writer')]));
    riRetryNode($run, 'pay', 3);

    $keys = RiLog::keysFor('pay');

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1])
        ->and($run->nodes()->where('node_id', 'pay')->first()->status)
        ->toBe(WorkflowRunNode::COMPLETED);
});

it('counts the attempt off the claim row, so a retry knows it is one', function () {
    Queue::fake();
    riWriter();
    RiLog::$failFor['pay'] = 1;

    $run = riRun(riSchema([riNode('pay', 'writer')]));
    riRetryNode($run, 'pay', 3);

    expect(array_column(RiLog::$seen, 'attempt'))->toBe([1, 2]);
});

it('keeps the first-attempt clock still across a retry, hours apart', function () {
    // `claimed_at` is refreshed on EVERY reclaim. An identity reading that
    // column would report a retry 25 hours late as seconds old, and a connector
    // would cheerfully reuse a key the provider forgot yesterday — creating the
    // second charge the whole mechanism exists to prevent.
    //
    // The hours are the test. Three attempts inside one second pass whichever
    // column is read, which is exactly how this would have shipped wrong.
    Queue::fake();
    riWriter();
    RiLog::$failFor['pay'] = 2;

    $run = riRun(riSchema([riNode('pay', 'writer')]));
    riRetryNode($run, 'pay', 3, fn () => Carbon::setTestNow(Carbon::now()->addHours(13)));
    Carbon::setTestNow();

    $clocks = array_unique(array_column(RiLog::$seen, 'firstAttemptAt'));

    expect(RiLog::$seen)->toHaveCount(3)
        ->and($clocks)->toHaveCount(1);
});

it('refuses to reuse a key once the provider window has elapsed', function () {
    // The other half of the same fact, stated as the decision a connector makes.
    // 26 hours after the first attempt, Stripe has forgotten the key: resending
    // it and minting a fresh one BOTH charge twice, so the only safe answer is
    // to refuse and let a person reconcile.
    Queue::fake();
    riWriter();
    RiLog::$failFor['pay'] = 1;

    $run = riRun(riSchema([riNode('pay', 'writer')]));
    riRetryNode($run, 'pay', 2, fn () => Carbon::setTestNow(Carbon::now()->addHours(26)));
    $now = Carbon::now();
    Carbon::setTestNow();

    $retry = RiLog::$seen[1];

    expect($retry['attempt'])->toBe(2)
        ->and((new RunIdentity($run->run_key, [], $retry['attempt'], $retry['firstAttemptAt']))
            ->isReplaySafe(86400, $now))->toBeFalse();
});

it('gives two nodes of one run different keys', function () {
    riWriter();

    FancyFlow::dispatch(riSchema(
        [riNode('a', 'writer'), riNode('b', 'writer')],
        [riEdge('e1', 'a', 'b')],
    ));

    $keys = array_column(RiLog::$seen, 'key');

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});

it('gives the same node in two runs different keys', function () {
    riWriter();

    FancyFlow::dispatch(riSchema([riNode('pay', 'writer')]));
    FancyFlow::dispatch(riSchema([riNode('pay', 'writer')]));

    $keys = RiLog::keysFor('pay');

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});

it('reports the identity as replay-safe on a first attempt', function () {
    riWriter();

    FancyFlow::dispatch(riSchema([riNode('pay', 'writer')]));

    $row = RiLog::$seen[0];

    expect((new RunIdentity('run_x', [], $row['attempt'], $row['firstAttemptAt']))
        ->isReplaySafe(86400))->toBeTrue();
});

// ── the park ───────────────────────────────────────────────────────────────

it('fires the request to the person and finishes the job there', function () {
    Event::fake([HumanInputRequested::class]);

    $run = FancyFlow::dispatch(riSchema(
        [riNode('t', 'manual_trigger'), riNode('ap', 'human_approval', ['title' => 'Ship it?']), riNode('o', 'output')],
        [riEdge('e1', 't', 'ap'), riEdge('e2', 'ap', 'o', 'approved')],
    ), ['t' => []]);

    Event::assertDispatched(
        HumanInputRequested::class,
        fn (HumanInputRequested $e) => $e->runId === $run->run_key
            && $e->nodeId === 'ap'
            && $e->awaiting === 'approval'
            && $e->isApproval()
            && ($e->detail['title'] ?? null) === 'Ship it?',
    );
});

it('holds no queued work while parked - nothing downstream is dispatched', function () {
    Queue::fake();
    riWriter();

    $run = FancyFlow::dispatch(riSchema(
        [riNode('ap', 'human_approval'), riNode('pay', 'writer')],
        [riEdge('e1', 'ap', 'pay', 'approved')],
    ));

    // Drain the chain the way a worker would, then look at what is left.
    riPump();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($run->nodes()->where('node_id', 'ap')->first()->status)->toBe(WorkflowRunNode::PAUSED);
    // The downstream node has no claim row at all: it was never dispatched, so
    // no worker, connection or process is holding anything open for the person.
    expect($run->nodes()->where('node_id', 'pay')->first())->toBeNull();
    expect(RiLog::$seen)->toBe([]);
});

it('is the recorded answer that enqueues the continuation', function () {
    Queue::fake();
    riWriter();

    $run = FancyFlow::dispatch(riSchema(
        [riNode('ap', 'human_approval'), riNode('pay', 'writer')],
        [riEdge('e1', 'ap', 'pay', 'approved')],
    ));
    riPump();

    $before = Queue::pushed(AdvanceWorkflowJob::class)->count();

    $run->refresh()->approve();

    // The inbound response is what queues the next job. Nothing was waiting.
    expect(Queue::pushed(AdvanceWorkflowJob::class)->count())->toBeGreaterThan($before);

    riPump();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect(RiLog::keysFor('pay'))->toHaveCount(1);
});

it('gives a node resumed after a week-long park a first-attempt key', function () {
    Queue::fake();
    riWriter();

    $run = FancyFlow::dispatch(riSchema(
        [riNode('ap', 'human_approval'), riNode('pay', 'writer')],
        [riEdge('e1', 'ap', 'pay', 'approved')],
    ));
    riPump();

    $run->refresh()->approve();
    riPump();

    // The writing node ran for the FIRST time after the park, so its own clock
    // starts then and nothing was sent for a provider to have forgotten. A
    // design that measured the window from the RUN's start would refuse this.
    expect(RiLog::$seen[0]['attempt'])->toBe(1);
    expect((new RunIdentity(
        $run->run_key,
        [],
        RiLog::$seen[0]['attempt'],
        RiLog::$seen[0]['firstAttemptAt'],
    ))->isReplaySafe(86400))->toBeTrue();
});

/** Drain the faked queue by hand, the way a worker would. */
function riPump(int $max = 200): void
{
    for ($i = 0; $i < $max; $i++) {
        $progress = false;

        foreach ([RunNodeJob::class, AdvanceWorkflowJob::class] as $class) {
            $jobs = Queue::pushed($class)->values();

            while ((RiLog::$cursor[$class] ?? 0) < $jobs->count()) {
                $index = RiLog::$cursor[$class] ?? 0;
                RiLog::$cursor[$class] = $index + 1;
                app()->call([$jobs[$index], 'handle']);
                $progress = true;
            }
        }

        if (! $progress) {
            return;
        }
    }
}
