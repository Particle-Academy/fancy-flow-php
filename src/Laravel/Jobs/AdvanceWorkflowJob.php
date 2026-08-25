<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Laravel\Events\WorkflowFailed;
use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\Events\WorkflowStarted;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Laravel\Runs\Frontier;
use FancyFlow\Laravel\Runs\GraphReplay;
use FancyFlow\Laravel\Runs\NodeClaims;
use FancyFlow\Laravel\Runs\RunSetup;
use FancyFlow\Laravel\TriggerCohort;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowGraph;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Move a run forward by one step: work out what can run now, and dispatch it.
 *
 * The bookkeeping half of the `per_node` queue driver (`fancy-flow.queue.driver`).
 * It executes nothing itself — {@see RunNodeJob} does that, and dispatches this
 * job back when it finishes. The loop is:
 *
 *     advance → N node jobs → advance → … → advance settles the run
 *
 * ## What each advance decides
 *
 *  - **Start.** On the first advance the cohort {@see \FancyFlow\Contracts\TriggerGuard}
 *    is re-checked and the graph is probed for a cycle. The guard is checked
 *    HERE and only here: it asks whether the run should begin at all, and
 *    re-asking it per node would let a mid-run change skip a half-executed
 *    workflow — which is worse than either answer.
 *  - **Frontier.** Which nodes are unblocked ({@see Frontier}), which are
 *    reached down a dead branch and must be recorded as skipped.
 *  - **Settle.** No frontier, nothing in flight, everything settled → the run is
 *    complete. Nothing settled and nothing to run → the graph cannot progress.
 *  - **Nothing.** Work is still in flight. Whichever node finishes will advance
 *    the run; this job returns rather than polling.
 *
 * Every one of those is derived from the `workflow_run_nodes` rows, so the job
 * is idempotent: dispatch it twice and the second one finds nothing to do. That
 * matters, because several things dispatch it — every node completion, every
 * lost claim, every resume.
 */
final class AdvanceWorkflowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Bookkeeping is not the risky part; a failed advance should be retried a
     * few times before a run is declared dead over a lock timeout.
     */
    public int $tries = 3;

    public function __construct(public readonly string $runKey)
    {
        $this->onConnection(config('fancy-flow.queue.connection'));
        $this->onQueue((string) config('fancy-flow.queue.queue', 'default'));
    }

    public function handle(
        FancyFlowManager $flow,
        Dispatcher $events,
        TriggerCohort $cohort,
        NodeKindRegistry $kinds,
    ): void {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null || in_array($run->status, [WorkflowRun::COMPLETED, WorkflowRun::FAILED, WorkflowRun::SKIPPED], true)) {
            return;
        }

        // Parked on a person. `approve()` / `submitInput()` record the answer and
        // set the run back to PENDING, which is what un-parks it.
        if ($run->isAwaitingHuman()) {
            return;
        }

        $starting = $run->status === WorkflowRun::PENDING;
        if ($starting) {
            // The paused node has to execute again to consume the answer that
            // was just recorded for it, so its claim goes.
            NodeClaims::clearPaused($this->runKey);
        }

        $rows = NodeClaims::all($this->runKey);
        $graph = $flow->toGraph($run->schema);

        // Has this run ever begun? `attempts` rather than "are there rows",
        // because a run that parked on its very FIRST node has no rows left once
        // the paused claim is released — and resuming it must not re-ask the
        // cohort guard, which would let a change of state skip a workflow that
        // is already half-executed.
        if ($rows->isEmpty() && $run->attempts === 0) {
            if (! $cohort->allows($run)) {
                $events->dispatch(new WorkflowSettled($this->runKey, WorkflowSettled::SKIPPED, $run->skipped_reason));
                $cohort->advance($run);

                return;
            }

            if (! $this->open($run, $flow, $graph, $events, $cohort)) {
                return;
            }

            $rows = NodeClaims::all($this->runKey);
        }

        if ($starting) {
            $run->forceFill(['status' => WorkflowRun::RUNNING, 'attempts' => $run->attempts + 1])->save();
            $events->dispatch(new WorkflowStarted($this->runKey));
        }

        $timeout = config('fancy-flow.timeout_ms');
        if ($timeout !== null && $this->elapsedMs($rows) > (int) $timeout) {
            // The engine checks its timeout between nodes rather than
            // interrupting one, and so does this: a node already in flight
            // finishes, and the run stops at the next decision point.
            $this->fail($run, $events, $cohort, "Run timed out after {$timeout}ms");

            return;
        }

        $frontier = Frontier::compute($graph, NodeClaims::state($rows), $run->entry_nodes);

        if ($frontier['skipped'] !== []) {
            foreach ($frontier['skipped'] as $nodeId) {
                NodeClaims::skip($this->runKey, $nodeId);
            }
            $rows = NodeClaims::all($this->runKey);
        }

        $state = NodeClaims::state($rows);
        $outputs = NodeClaims::outputs($rows);

        // The pre-0.10 checkpoint, kept current. Hosts read `node_outputs`, and
        // the single-job driver still resumes from it — so a run can be handed
        // between drivers without losing its place.
        $run->forceFill(['node_outputs' => $outputs])->save();

        if ($frontier['ready'] !== []) {
            foreach ($frontier['ready'] as $nodeId) {
                $node = $graph->node($nodeId);
                if ($node !== null) {
                    RunNodeJob::dispatchFor($this->runKey, $node, $kinds);
                }
            }

            return;
        }

        // Nothing is runnable. Either the run is done, or it is waiting on work
        // someone else holds, or it cannot progress at all.
        if (Frontier::hasWorkInFlight($state)) {
            return;
        }

        if (Frontier::isComplete($graph, $state)) {
            $this->complete($run, $outputs, $events, $cohort);

            return;
        }

        foreach ($rows as $row) {
            if ($row->status === WorkflowRunNode::FAILED) {
                $this->fail($run, $events, $cohort, (string) ($row->error ?? 'workflow run failed'));

                return;
            }
        }

        // Unsettled nodes, none of them reachable, nothing running. In a DAG
        // every node eventually becomes ready, so the graph is not one — which
        // is the same conclusion the engine reaches, in the same words.
        $this->fail($run, $events, $cohort, 'Cycle detected in flow graph — aborting.');
    }

    /**
     * First advance: prove the graph can run, and adopt any checkpoint written
     * by the single-job driver.
     *
     * The probe replays with EVERY node fenced off, so nothing executes. What
     * comes back is what the engine can tell structurally — a cycle, and (for a
     * run that already has `node_outputs`) the ports each stored output
     * republishes on, which is what the frontier needs to know which edges those
     * nodes made live.
     *
     * @return bool false when the run has been failed and this advance is over
     */
    private function open(
        WorkflowRun $run,
        FancyFlowManager $flow,
        FlowGraph $graph,
        Dispatcher $events,
        TriggerCohort $cohort,
    ): bool {
        $checkpoint = $run->node_outputs ?? [];

        $probe = GraphReplay::upTo(
            $flow,
            $graph,
            nodeId: null,
            executors: RunSetup::executors($flow, $run),
            options: new RunOptions(
                initialInputs: RunSetup::initialInputs($run),
                entryNodes: $run->entry_nodes,
                resumeOutputs: $checkpoint,
            ),
            runId: $run->run_key,
        );

        $error = $probe['result']->error;
        if ($error !== null && ! GraphReplay::isBoundary($error)) {
            $this->fail($run, $events, $cohort, $error);

            return false;
        }

        foreach ($checkpoint as $nodeId => $output) {
            NodeClaims::adopt($this->runKey, (string) $nodeId, $output, $probe['ports'][$nodeId] ?? []);
        }

        return true;
    }

    /** @param array<string,mixed> $outputs */
    private function complete(WorkflowRun $run, array $outputs, Dispatcher $events, TriggerCohort $cohort): void
    {
        $run->forceFill([
            'status' => WorkflowRun::COMPLETED,
            'outputs' => $outputs,
            'node_outputs' => $outputs,
            'error' => null,
        ])->save();

        $events->dispatch(new WorkflowFinished($this->runKey, true, $outputs));
        $events->dispatch(new WorkflowSettled($this->runKey, WorkflowSettled::COMPLETED));
        $cohort->advance($run);
    }

    private function fail(WorkflowRun $run, Dispatcher $events, TriggerCohort $cohort, string $error): void
    {
        $run->forceFill(['status' => WorkflowRun::FAILED, 'error' => $error])->save();

        $events->dispatch(new WorkflowSettled($this->runKey, WorkflowSettled::FAILED, $error));
        $events->dispatch(new WorkflowFailed($this->runKey, $error));
        $cohort->advance($run);
    }

    /**
     * Bookkeeping gave up.
     *
     * A run whose advance cannot run is finished whether or not anyone says so —
     * nothing else will move it, because every step of this driver is kicked off
     * by the previous one. Recording that is the difference between a failed run
     * and a run that sits in `running` forever looking like it is working.
     */
    public function failed(Throwable $e): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null || in_array($run->status, [WorkflowRun::COMPLETED, WorkflowRun::FAILED, WorkflowRun::SKIPPED], true)) {
            return;
        }

        $this->fail($run, app(Dispatcher::class), app(TriggerCohort::class), $e->getMessage());
    }

    /**
     * How long this run has been executing — measured from the first node claim
     * rather than from the run row, which may have sat on the queue for hours
     * before a worker picked it up.
     *
     * @param  iterable<WorkflowRunNode>  $rows
     */
    private function elapsedMs(iterable $rows): float
    {
        $earliest = null;
        foreach ($rows as $row) {
            $claimed = $row->claimed_at;
            if ($claimed !== null && ($earliest === null || $claimed->lt($earliest))) {
                $earliest = $claimed;
            }
        }

        return $earliest === null ? 0.0 : (float) (Carbon::now()->getTimestampMs() - $earliest->getTimestampMs());
    }
}
