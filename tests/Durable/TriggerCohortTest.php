<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Contracts\TriggerGuard;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Workflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema as DbSchema;

uses(\FancyFlow\Tests\Durable\DurableTestCase::class);

/**
 * Trigger collision.
 *
 * Fan one event out to several workflows the old way — a loop over dispatch() —
 * and you get N independent jobs in arbitrary order. If one of them deletes the
 * record they were all fired for, the rest run afterwards over nothing and
 * report SUCCESS. These tests pin the cohort behaviour that ends that: declared
 * order, one at a time, guard re-checked immediately before each run.
 */

beforeEach(function () {
    TraceExecutor::$log = [];

    DbSchema::create('cohort_deals', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
    });
    CohortDeal::query()->create(['id' => 1, 'name' => 'Acme']);

    FancyFlow::extend('trace', TraceExecutor::class, [
        'name' => 'trace', 'category' => 'logic', 'label' => 'Trace',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);
    FancyFlow::extend('drop_deal', DropDealExecutor::class, [
        'name' => 'drop_deal', 'category' => 'logic', 'label' => 'Drop Deal',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);
});

function cschema(array $nodes, array $edges = []): array
{
    return ['$schema' => Workflow::SCHEMA_URL, 'version' => 1, 'graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function cnode(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'position' => ['x' => 0, 'y' => 0], 'config' => $config];
}

/** A one-node flow that records `$tag` when it runs. */
function tracing(string $tag): array
{
    return cschema(
        [cnode('t', 'manual_trigger'), cnode('x', 'trace', ['tag' => $tag])],
        [['id' => 'e1', 'source' => 't', 'target' => 'x']],
    );
}

/** A one-node flow that deletes deal 1 — the collision, in one node. */
function dropping(): array
{
    return cschema(
        [cnode('t', 'manual_trigger'), cnode('d', 'drop_deal')],
        [['id' => 'e1', 'source' => 't', 'target' => 'd']],
    );
}

function dealGuard(): array
{
    return ['name' => 'record-exists', 'args' => ['model' => CohortDeal::class, 'id' => 1]];
}

// ── Ordering ────────────────────────────────────────────────────────────────

it('runs a cohort in the order it was declared, not the order the queue felt like', function () {
    FancyFlow::dispatchCohort([tracing('a'), tracing('b'), tracing('c')]);

    expect(TraceExecutor::$log)->toBe(['a', 'b', 'c']);
});

it('enqueues only the head of a serial cohort — each run hands on to the next', function () {
    Queue::fake();

    $runs = FancyFlow::dispatchCohort([tracing('a'), tracing('b'), tracing('c')]);

    // All three are persisted and ordered…
    expect($runs)->toHaveCount(3);
    expect(array_map(fn (WorkflowRun $r) => $r->cohort_seq, $runs))->toBe([0, 1, 2]);
    expect(collect($runs)->pluck('cohort_key')->unique())->toHaveCount(1);

    // …but only one job exists at a time. That is what makes the ordering real
    // rather than merely recorded.
    Queue::assertPushed(RunWorkflowJob::class, 1);
});

it('enqueues everything at once under the parallel policy', function () {
    Queue::fake();

    FancyFlow::dispatchCohort([tracing('a'), tracing('b')], policy: WorkflowRun::POLICY_PARALLEL);

    Queue::assertPushed(RunWorkflowJob::class, 2);
});

// ── The collision itself ────────────────────────────────────────────────────

it('skips a run whose trigger record was deleted by an earlier run in the cohort', function () {
    $runs = FancyFlow::dispatchCohort(
        [dropping(), tracing('after')],
        ['t' => ['deal' => 1]],
        guard: dealGuard(),
    );

    [$dropper, $victim] = [$runs[0]->refresh(), $runs[1]->refresh()];

    expect($dropper->status)->toBe(WorkflowRun::COMPLETED);

    // The second run never executed — and says why.
    expect($victim->status)->toBe(WorkflowRun::SKIPPED);
    expect($victim->skipped_reason)->toContain('no longer exists');
    expect(TraceExecutor::$log)->toBe([]);
});

it('runs the whole cohort when the trigger record survives', function () {
    $runs = FancyFlow::dispatchCohort(
        [tracing('a'), tracing('b')],
        guard: dealGuard(),
    );

    expect(TraceExecutor::$log)->toBe(['a', 'b']);
    expect($runs[1]->refresh()->status)->toBe(WorkflowRun::COMPLETED);
    expect($runs[1]->skipped_reason)->toBeNull();
});

it('is exactly the silent failure the guard exists to prevent, without one', function () {
    // The `serial` policy is ordered but unguarded — deliberately the old
    // behaviour, kept for fan-outs that share no state. Pinned here so the
    // difference the guard makes is visible rather than asserted.
    FancyFlow::dispatchCohort(
        [dropping(), tracing('after')],
        guard: dealGuard(),
        policy: WorkflowRun::POLICY_SERIAL,
    );

    // It ran, over a record that no longer exists, and reported success.
    expect(TraceExecutor::$log)->toBe(['after']);
    expect(CohortDeal::query()->count())->toBe(0);
});

it('carries a per-run payload snapshot, so an earlier run cannot rewrite a later one', function () {
    $runs = FancyFlow::dispatchCohort([dropping(), tracing('after')], ['t' => ['deal' => 1, 'name' => 'Acme']]);

    // Taken at dispatch and stored on each run — not re-read from the record at
    // execution time, which by then may be gone.
    expect($runs[1]->refresh()->initial_inputs)->toBe(['t' => ['deal' => 1, 'name' => 'Acme']]);
});

// ── Guard behaviour ─────────────────────────────────────────────────────────

it('fails closed when a guard throws', function () {
    config()->set('fancy-flow.guards', ['boom' => ThrowingGuard::class]);

    $runs = FancyFlow::dispatchCohort([tracing('a')], guard: ['name' => 'boom']);

    $run = $runs[0]->refresh();
    expect($run->status)->toBe(WorkflowRun::SKIPPED);
    expect($run->skipped_reason)->toContain('could not be evaluated');
    expect(TraceExecutor::$log)->toBe([]);
});

it('fails closed when a guard name resolves to something that is not a guard', function () {
    config()->set('fancy-flow.guards', ['bogus' => \stdClass::class]);

    $run = FancyFlow::dispatchCohort([tracing('a')], guard: ['name' => 'bogus'])[0]->refresh();

    expect($run->status)->toBe(WorkflowRun::SKIPPED);
    expect($run->skipped_reason)->toContain('not a '.TriggerGuard::class);
});

it('lets a host override the built-in record-exists guard by name', function () {
    config()->set('fancy-flow.guards', ['record-exists' => AlwaysDenyGuard::class]);

    $run = FancyFlow::dispatchCohort([tracing('a')], guard: dealGuard())[0]->refresh();

    expect($run->status)->toBe(WorkflowRun::SKIPPED);
    expect($run->skipped_reason)->toBe('host says no');
});

it('leaves ordinary dispatch() completely alone', function () {
    $run = FancyFlow::dispatch(tracing('solo'))->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);
    expect($run->cohort_key)->toBeNull();
    expect(TraceExecutor::$log)->toBe(['solo']);
});

// ── Handing the cohort on ───────────────────────────────────────────────────

it('holds the cohort while a run waits on a human', function () {
    $runs = FancyFlow::dispatchCohort([
        cschema(
            [cnode('t', 'manual_trigger'), cnode('ap', 'human_approval'), cnode('x', 'trace', ['tag' => 'approved'])],
            [['id' => 'e1', 'source' => 't', 'target' => 'ap'], ['id' => 'e2', 'source' => 'ap', 'target' => 'x', 'sourceHandle' => 'approved']],
        ),
        tracing('next'),
    ]);

    // A pause is not a settle. Advancing here would defeat the point: the whole
    // reason to serialize is that the next run should observe this one's
    // effects, and a person has not decided yet.
    expect($runs[0]->refresh()->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($runs[1]->refresh()->status)->toBe(WorkflowRun::PENDING);
    expect(TraceExecutor::$log)->toBe([]);

    $runs[0]->approve();

    expect(TraceExecutor::$log)->toBe(['approved', 'next']);
    expect($runs[1]->refresh()->status)->toBe(WorkflowRun::COMPLETED);
});

it('hands the cohort on when a run fails for good', function () {
    FancyFlow::extend('explode_cohort', fn (ExecutionContext $ctx) => throw new RuntimeException('boom'), [
        'name' => 'explode_cohort', 'category' => 'logic', 'label' => 'Explode',
        'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
    ]);

    try {
        FancyFlow::dispatchCohort([
            cschema(
                [cnode('t', 'manual_trigger'), cnode('x', 'explode_cohort')],
                [['id' => 'e1', 'source' => 't', 'target' => 'x']],
            ),
            tracing('after-failure'),
        ]);
    } catch (Throwable) {
        // The sync queue surfaces the retry throw; failed() still runs.
    }

    // A failed run does not cancel the cohort — the guard, not the failure,
    // decides whether the next run still has state to act on.
    expect(TraceExecutor::$log)->toBe(['after-failure']);
});

it('settles a skipped run so a host still tears its context down', function () {
    Event::fake([WorkflowSettled::class]);

    config()->set('fancy-flow.guards', ['deny' => AlwaysDenyGuard::class]);
    FancyFlow::dispatchCohort([tracing('a')], guard: ['name' => 'deny']);

    Event::assertDispatched(WorkflowSettled::class, function (WorkflowSettled $e) {
        return $e->outcome === WorkflowSettled::SKIPPED
            && $e->isTerminal()
            && $e->error === 'host says no';
    });
});

// ── Fixtures ────────────────────────────────────────────────────────────────

final class CohortDeal extends Model
{
    protected $table = 'cohort_deals';

    protected $guarded = [];

    public $timestamps = false;
}

final class TraceExecutor implements NodeExecutor
{
    /** @var list<string> */
    public static array $log = [];

    public function execute(ExecutionContext $ctx): mixed
    {
        $tag = (string) $ctx->option('tag', '?');
        self::$log[] = $tag;

        return $tag;
    }
}

final class DropDealExecutor implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        CohortDeal::query()->whereKey(1)->delete();

        return 'dropped';
    }
}

final class ThrowingGuard implements TriggerGuard
{
    public function passes(array $args, array $context): bool
    {
        throw new RuntimeException('the guard itself is broken');
    }

    public function reason(array $args, array $context): string
    {
        return 'unreachable';
    }
}

final class AlwaysDenyGuard implements TriggerGuard
{
    public function passes(array $args, array $context): bool
    {
        return false;
    }

    public function reason(array $args, array $context): string
    {
        return 'host says no';
    }
}
