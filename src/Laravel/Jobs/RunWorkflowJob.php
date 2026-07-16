<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Nodes\DurableApprovalExecutor;
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

        $run->forceFill(['status' => WorkflowRun::RUNNING, 'attempts' => $run->attempts + 1])->save();

        // Merge recorded approval decisions into the entry inputs for their nodes.
        $initial = $run->initial_inputs ?? [];
        foreach ($run->approvals ?? [] as $nodeId => $approved) {
            $initial[$nodeId] = array_merge($initial[$nodeId] ?? [], ['approved' => $approved]);
        }

        $executors = $flow->executors()->fork()->bind('human_approval', DurableApprovalExecutor::class);
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

            return;
        }

        // A human_approval node paused the run — halt and wait for a decision.
        if (is_string($result->error) && str_starts_with($result->error, DurableApprovalExecutor::PAUSE_PREFIX)) {
            $node = substr($result->error, strlen(DurableApprovalExecutor::PAUSE_PREFIX));
            $run->forceFill(['status' => WorkflowRun::AWAITING_APPROVAL, 'awaiting_node' => $node])->save();

            return;
        }

        // Genuine failure — throw so the queue retries (resuming from the checkpoint).
        throw new WorkflowRunFailed($result->error ?? 'workflow run failed');
    }

    public function failed(Throwable $e): void
    {
        WorkflowRun::query()
            ->where('run_key', $this->runKey)
            ->first()
            ?->forceFill(['status' => WorkflowRun::FAILED, 'error' => $e->getMessage()])
            ->save();
    }
}
