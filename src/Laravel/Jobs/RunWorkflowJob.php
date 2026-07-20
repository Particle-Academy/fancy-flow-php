<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Laravel\Events\WorkflowFailed;
use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Nodes\DurableApprovalExecutor;
use FancyFlow\Laravel\Nodes\DurableUserInputExecutor;
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
 * Runs a {@see WorkflowRun} on a queue, durably:
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

    /** Dispatch this job for a run, honoring the configured connection + queue. */
    public static function enqueue(WorkflowRun $run): void
    {
        self::dispatch($run->run_key);
    }

    public function backoff(): int
    {
        return (int) config('fancy-flow.queue.backoff', 0);
    }

    public function handle(FancyFlowManager $flow, Dispatcher $events): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null || $run->status === WorkflowRun::COMPLETED) {
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

        // Merge recorded approval decisions into the entry inputs for their nodes.
        $initial = $run->initial_inputs ?? [];
        foreach ($run->approvals ?? [] as $nodeId => $approved) {
            $initial[$nodeId] = array_merge($initial[$nodeId] ?? [], ['approved' => $approved]);
        }

        // …and recorded form submissions, which resume a paused `user_input`.
        foreach ($run->submissions ?? [] as $nodeId => $values) {
            $initial[$nodeId] = array_merge($initial[$nodeId] ?? [], ['values' => $values]);
        }

        $executors = $flow->executors()->fork()
            ->bind('human_approval', DurableApprovalExecutor::class)
            ->bind('user_input', DurableUserInputExecutor::class);
        $options = new RunOptions(
            timeoutMs: config('fancy-flow.timeout_ms'),
            initialInputs: $initial,
            resumeOutputs: $run->node_outputs ?? [],
        );

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
        WorkflowRun::query()
            ->where('run_key', $this->runKey)
            ->first()
            ?->forceFill(['status' => WorkflowRun::FAILED, 'error' => $e->getMessage()])
            ->save();

        // The run is terminally failed once retries are exhausted, and nothing
        // announced it — only the success path dispatched an outcome event.
        // WorkflowSettled is NOT re-emitted here: each attempt already settled
        // in handle()'s finally, so a second one would report teardown for a
        // run that was already torn down.
        event(new WorkflowFailed($this->runKey, $e->getMessage()));
    }
}
