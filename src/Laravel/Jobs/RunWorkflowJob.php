<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Laravel\Events\WorkflowFailed;
use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Runs\RunSetup;
use FancyFlow\Laravel\TriggerCohort;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\RunOptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a {@see WorkflowRun} on a queue, durably — the `single` queue driver:
 * one job for the whole graph.
 *
 * **This is one of two drivers.** `fancy-flow.queue.driver` selects between:
 *
 *   - `single` (default) — this job. The whole graph runs in one `handle()`,
 *     and the `node_outputs` checkpoint is written once, when it returns.
 *   - `per_node` — {@see AdvanceWorkflowJob} + {@see RunNodeJob}. A job per
 *     node, each checkpointing its own result, so a worker killed mid-run loses
 *     at most the node that was in flight rather than everything completed since
 *     the last whole-graph write.
 *
 * {@see enqueue()} routes to the configured driver, so hosts calling it — and
 * everything in this package does — keep working either way.
 *
 * What this driver does:
 *
 *   - **Resume** — feeds the run's `node_outputs` checkpoint as
 *     {@see RunOptions::$resumeOutputs}, so a retry skips already-completed
 *     nodes and re-runs only from the failure point.
 *   - **Retry** — a genuine node failure re-throws so the queue retries (up to
 *     `queue.tries`); the checkpoint is persisted first, so each attempt resumes.
 *   - **Approval pause** — a `human_approval` node with no recorded decision
 *     halts the run (status `awaiting_approval`) instead of failing;
 *     {@see WorkflowRun::approve()} records the decision and re-queues this job.
 *   - **Input pause** — a `user_input` node with no recorded submission halts
 *     the run (status `awaiting_input`) instead of passing empty values on;
 *     {@see WorkflowRun::submitInput()} records the typed values payload and
 *     re-queues this job. {@see WorkflowRun::awaitingForm()} exposes the form
 *     to render while paused.
 *   - **Trigger collision** — a run belonging to a {@see TriggerCohort} re-checks
 *     its guard just before starting and hands the cohort on when it settles, so
 *     workflows fired by the same event run in order and a run whose state has
 *     vanished is `skipped` with a reason rather than executing over nothing.
 */
final class RunWorkflowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(public readonly string $runKey)
    {
        $this->tries = max(1, (int) config('fancy-flow.queue.tries', 1));
        $this->onConnection(config('fancy-flow.queue.connection'));
        $this->onQueue((string) config('fancy-flow.queue.queue', 'default'));
    }

    /**
     * Start (or resume) a run on the configured driver.
     *
     * The single entry point every caller in this package already uses —
     * `FancyFlow::dispatch()`, `dispatchCohort()`, `TriggerCohort::advance()`,
     * `WorkflowRun::approve()` / `submitInput()`. Routing here rather than at
     * each of those is what lets `queue.driver` be a config change instead of a
     * migration of every call site, including the ones in host code.
     */
    public static function enqueue(WorkflowRun $run): void
    {
        if (self::perNode()) {
            AdvanceWorkflowJob::dispatch($run->run_key);

            return;
        }

        self::dispatch($run->run_key);
    }

    /** Is the per-node driver selected? */
    public static function perNode(): bool
    {
        return config('fancy-flow.queue.driver', 'single') === 'per_node';
    }

    public function backoff(): int
    {
        return (int) config('fancy-flow.queue.backoff', 0);
    }

    public function handle(FancyFlowManager $flow, Dispatcher $events, TriggerCohort $cohort): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null || $run->status === WorkflowRun::COMPLETED) {
            return;
        }

        // Re-check the trigger precondition at the LAST possible moment — not at
        // dispatch. In a serialized cohort the runs ahead of this one have
        // already finished, and one of them may have deleted the very record
        // this run was queued for. Checking now is the difference between a
        // recorded skip and a run that completes over nothing (#collision).
        if (! $cohort->allows($run)) {
            $events->dispatch(new WorkflowSettled($this->runKey, WorkflowSettled::SKIPPED, $run->skipped_reason));
            $cohort->advance($run);

            return;
        }

        // Every exit below must emit exactly one WorkflowSettled, including the
        // throw that triggers a retry. `WorkflowStarted` fires when the run
        // begins, so anything a host binds for the run's duration needs a
        // guaranteed counterpart or it leaks onto the worker (#2).
        $outcome = WorkflowSettled::ERRORED;
        $settleError = null;

        try {
            $this->execute($run, $flow, $events, $outcome, $settleError);
        } finally {
            $events->dispatch(new WorkflowSettled($this->runKey, $outcome, $settleError));

            // Hand the cohort on only when this run is genuinely done with the
            // shared state. A pause is still holding it (a person is deciding),
            // and an ERRORED attempt may yet be retried — that one advances from
            // failed(), once the queue gives up for good.
            if ($outcome === WorkflowSettled::COMPLETED) {
                $cohort->advance($run);
            }
        }
    }

    /**
     * The run itself. Reports how it ended through $outcome/$settleError so the
     * caller's `finally` can settle even when this throws.
     *
     * @param  WorkflowSettled::*  $outcome
     */
    private function execute(
        WorkflowRun $run,
        FancyFlowManager $flow,
        Dispatcher $events,
        ?string &$outcome,
        ?string &$settleError,
    ): void {

        $run->forceFill(['status' => WorkflowRun::RUNNING, 'attempts' => $run->attempts + 1])->save();

        // Recorded approvals and form submissions become the entry inputs their
        // nodes read on resume. Shared with the per-node driver so the two can
        // never disagree about what "resumed" means.
        $options = new RunOptions(
            timeoutMs: config('fancy-flow.timeout_ms'),
            initialInputs: RunSetup::initialInputs($run),
            resumeOutputs: $run->node_outputs ?? [],
        );
        $executors = RunSetup::executors($flow);

        $result = $flow->run(
            $run->schema,
            options: $options,
            runId: $run->run_key,
            executors: $executors,
            emitTerminalEvents: false,
        );

        // Checkpoint the completed-node outputs regardless of outcome — this is
        // what a retry resumes from.
        $run->forceFill(['node_outputs' => $result->outputs])->save();

        if ($result->ok) {
            $run->forceFill([
                'status' => WorkflowRun::COMPLETED,
                'outputs' => $result->outputs,
                'error' => null,
            ])->save();
            $events->dispatch(new WorkflowFinished($run->run_key, true, $result->outputs));
            $outcome = WorkflowSettled::COMPLETED;

            return;
        }

        // A node paused the run to wait for a person — halt rather than fail.
        //
        // One decode, not a branch per kind. This used to be two str_starts_with
        // checks against constants owned by two BUILTIN executors, which meant a
        // third-party human-input node could not participate at all: its pause
        // fell through to the failure path below and the queue retried it until
        // it exhausted its tries. Pause::decode understands the public contract
        // AND both legacy prefixes, so runs parked by an older version still
        // resume here.
        if ($pause = Pause::decode($result->error)) {
            $run->forceFill([
                'status' => match ($pause->awaiting) {
                    'approval' => WorkflowRun::AWAITING_APPROVAL,
                    'input' => WorkflowRun::AWAITING_INPUT,
                    // A wait this package does not define. Recording the kind is
                    // what lets a host render the right prompt on resume.
                    default => WorkflowRun::AWAITING_HUMAN,
                },
                'awaiting_node' => $pause->nodeId,
                'awaiting_kind' => $pause->awaiting,
                'awaiting_detail' => $pause->detail,
            ])->save();

            $outcome = match ($pause->awaiting) {
                'approval' => WorkflowSettled::AWAITING_APPROVAL,
                'input' => WorkflowSettled::AWAITING_INPUT,
                default => WorkflowSettled::AWAITING_HUMAN,
            };

            return;
        }

        // Genuine failure — throw so the queue retries (resuming from the checkpoint).
        $settleError = $result->error ?? 'workflow run failed';
        throw new WorkflowRunFailed($settleError);
    }

    public function failed(Throwable $e): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        $run?->forceFill(['status' => WorkflowRun::FAILED, 'error' => $e->getMessage()])->save();

        // Retries are exhausted, so the cohort moves on. A failed run does NOT
        // cancel the rest: the guard is what decides whether the next one still
        // has state to act on, and "the run before me failed" is not by itself
        // an answer to that question.
        if ($run !== null) {
            app(TriggerCohort::class)->advance($run);
        }

        // The run is terminally failed once retries are exhausted, and nothing
        // announced it — only the success path dispatched an outcome event.
        // WorkflowSettled is NOT re-emitted here: each attempt already settled
        // in handle()'s finally, so a second one would report teardown for a
        // run that was already torn down.
        event(new WorkflowFailed($this->runKey, $e->getMessage()));
    }
}
