<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Laravel\Events\WorkflowFailed;
use FancyFlow\Laravel\Events\HumanInputRequested;
use FancyFlow\Laravel\Events\WorkflowSettled;
use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Laravel\Models\WorkflowRunNode;
use FancyFlow\Laravel\Runs\Frontier;
use FancyFlow\Laravel\Runs\GraphReplay;
use FancyFlow\Laravel\Runs\NodeClaims;
use FancyFlow\Laravel\Runs\NodeRetryPolicy;
use FancyFlow\Laravel\Runs\RunSetup;
use FancyFlow\Laravel\TriggerCohort;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Execute ONE node of a run.
 *
 * The executing half of the `per_node` queue driver. Where the single-job driver
 * ran a whole graph in one `handle()` and wrote its checkpoint once, at the end,
 * this claims a node, runs it, and writes that node's result before returning —
 * so a worker killed mid-run loses at most the node that was in flight.
 *
 * ## Claim first, always
 *
 * The very first thing is {@see NodeClaims::claim()}, a unique-constrained
 * insert. It is deliberately ahead of every other check: the race this exists to
 * close is two workers executing the same node, and a claim taken after the work
 * starts closes nothing. **Losing the claim is a no-op** — the other worker owns
 * the node and will carry the run on.
 *
 * ## Retries are per node now
 *
 * `tries` comes from {@see NodeRetryPolicy}, computed at dispatch. A node whose
 * kind declares `sideEffects: unsafe-to-replay` gets exactly one attempt, so a
 * failure cannot open a second pull request; a flaky LLM or HTTP node can be
 * given several without putting the nodes around it at risk. Under one job per
 * graph these were the same setting, and it could not be both.
 *
 * ## Drain
 *
 * A queue round trip per node is real overhead, and for a chain of fast nodes it
 * is most of the cost. With `queue.drain_limit` above zero this job keeps going
 * inline while the next step is unambiguous and cheap to be wrong about — one
 * ready successor, single-attempt, no human wait. Fan-out still dispatches, so
 * parallelism is never traded away. It is OFF by default: it trades a little of
 * the durability this driver exists to provide for latency, and that should be a
 * decision rather than a surprise.
 */
final class RunNodeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    /**
     * This dispatch's identity, minted once and carried through every attempt.
     *
     * A released job comes back and finds a claim — its own. Without a token to
     * recognise it by, the job would read that as "another worker has this",
     * return, and the run would stall on a node nobody is running. See
     * {@see NodeClaims}.
     */
    public readonly string $token;

    private int $backoffSeconds;

    public function __construct(
        public readonly string $runKey,
        public readonly string $nodeId,
        int $tries = 1,
        int $backoff = 0,
    ) {
        $this->tries = max(1, $tries);
        $this->backoffSeconds = max(0, $backoff);
        $this->token = bin2hex(random_bytes(16));
        $this->onConnection(config('fancy-flow.queue.connection'));
        $this->onQueue((string) config('fancy-flow.queue.queue', 'default'));
    }

    /** Queue this node with the retry policy its kind earns. */
    public static function dispatchFor(string $runKey, FlowNode $node, NodeKindRegistry $kinds): void
    {
        $config = (array) config('fancy-flow');

        self::dispatch(
            $runKey,
            $node->id,
            NodeRetryPolicy::tries($node, $kinds, $config),
            NodeRetryPolicy::backoff($node, $kinds, $config),
        );
    }

    public function backoff(): int
    {
        return $this->backoffSeconds;
    }

    public function handle(FancyFlowManager $flow, Dispatcher $events, NodeKindRegistry $kinds): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null || in_array($run->status, [WorkflowRun::COMPLETED, WorkflowRun::FAILED, WorkflowRun::SKIPPED], true)) {
            return;
        }
        if ($run->isAwaitingHuman()) {
            return;
        }

        if (NodeClaims::claim($this->runKey, $this->nodeId, $this->token) === NodeClaims::LOST) {
            // Someone else owns the node, or it is already settled. Nothing to
            // do — but hand the run on regardless: a node that finished while
            // this job was queued must not leave the run waiting on an advance
            // that never comes. Advancing is idempotent, so an extra one costs
            // a query and decides nothing twice.
            AdvanceWorkflowJob::dispatch($this->runKey);

            return;
        }

        $graph = $flow->toGraph($run->schema);
        $nodeId = $this->nodeId;
        $budget = $this->drainBudget();

        while (true) {
            $settled = $this->execute($flow, $events, $run, $graph, $nodeId);

            // A pause has already parked the run and announced it. Advancing now
            // would only rediscover that the run is waiting on a person.
            if ($settled === WorkflowRunNode::PAUSED) {
                return;
            }

            if ($settled !== WorkflowRunNode::COMPLETED || $budget-- <= 0) {
                break;
            }

            $next = $this->drainTarget($graph, $kinds, $run->entry_nodes);
            if ($next === null || NodeClaims::claim($this->runKey, $next, $this->token) !== NodeClaims::WON) {
                break;
            }

            $nodeId = $next;
        }

        AdvanceWorkflowJob::dispatch($this->runKey);
    }

    /**
     * Run one node through the engine and record what it did.
     *
     * @return WorkflowRunNode::COMPLETED|WorkflowRunNode::SKIPPED|WorkflowRunNode::PAUSED
     */
    private function execute(
        FancyFlowManager $flow,
        Dispatcher $events,
        WorkflowRun $run,
        FlowGraph $graph,
        string $nodeId,
    ): string {
        $rows = NodeClaims::all($this->runKey);

        $replay = GraphReplay::upTo(
            $flow,
            $graph,
            nodeId: $nodeId,
            executors: RunSetup::executors($flow, $run),
            options: new RunOptions(
                // No engine timeout here: it is checked between nodes, and there
                // is only ever one node in this run. The run-level deadline is
                // enforced by AdvanceWorkflowJob, which is the only place that
                // knows how long the whole run has been going.
                initialInputs: RunSetup::initialInputs($run),
                entryNodes: $run->entry_nodes,
                resumeOutputs: NodeClaims::outputs($rows),
                // Per NODE, off the claim row — not per run. This is the only
                // place in the suite where `attempt` and the first-attempt clock
                // are EXACT rather than conservative, and they are what a writing
                // connector checks a provider's idempotency window against.
                run: RunSetup::identityFor($run, $rows, $nodeId),
            ),
            runId: $run->run_key,
        );

        $result = $replay['result'];

        // The node ran. Its output and the ports it lit are written together
        // with the status, so there is no moment where it reads as done with
        // nothing to resume from.
        if (array_key_exists($nodeId, $result->outputs)) {
            NodeClaims::complete($this->runKey, $nodeId, $result->outputs[$nodeId], $replay['ports'][$nodeId] ?? []);

            return WorkflowRunNode::COMPLETED;
        }

        if ($pause = Pause::decode($result->error)) {
            // ONE unit. A worker dying between the claim and the run status
            // used to leave the node settled as paused with the run still
            // reading `running` -- unrecoverable, because nothing re-dispatches
            // a settled node and a human answering is rejected for a run that
            // is not parked. Unlike a completed node, whose ports the Frontier
            // recomputes, the awaiting_* fields exist only here.
            NodeClaims::pauseAndPark($this->runKey, $nodeId, $run, [
                'status' => match ($pause->awaiting) {
                    'approval' => WorkflowRun::AWAITING_APPROVAL,
                    'input' => WorkflowRun::AWAITING_INPUT,
                    default => WorkflowRun::AWAITING_HUMAN,
                },
                'awaiting_node' => $pause->nodeId,
                'awaiting_kind' => $pause->awaiting,
                'awaiting_detail' => $pause->detail,
            ]);

            // FIRE THE REQUEST, then finish. This is the whole of "a waiting
            // human holds no worker": the job's work at a gate is to ask, and
            // asking is done the moment this event is dispatched. The job
            // returns; the answer, whenever it arrives, enqueues the next one.
            $events->dispatch(new HumanInputRequested(
                $this->runKey,
                $pause->nodeId,
                $pause->awaiting,
                $pause->detail,
            ));

            $events->dispatch(new WorkflowSettled($this->runKey, match ($pause->awaiting) {
                'approval' => WorkflowSettled::AWAITING_APPROVAL,
                'input' => WorkflowSettled::AWAITING_INPUT,
                default => WorkflowSettled::AWAITING_HUMAN,
            }));

            return WorkflowRunNode::PAUSED;
        }

        // The engine reached the fence without running this node, which means it
        // decided the node was unreachable — every incoming edge dead. The
        // frontier normally catches that first; honouring the engine's verdict
        // here too means the two can never disagree about a branch.
        if (GraphReplay::isBoundary($result->error) || $result->ok) {
            NodeClaims::skip($this->runKey, $nodeId);

            return WorkflowRunNode::SKIPPED;
        }

        // A genuine failure. Throwing is what gives the node its retries; if it
        // has none left, failed() settles it and the run.
        throw new WorkflowRunFailed($result->error ?? 'workflow node failed');
    }

    /**
     * The next node to run inline, or null to hand back to the queue.
     *
     * Deliberately narrow. Drain is only safe where the choice is obvious and
     * the node is cheap to be wrong about:
     *
     *  - exactly ONE node is ready — two would mean serialising a fan-out that
     *    the queue could have run in parallel;
     *  - the node gets a single attempt anyway, so nothing is lost by running it
     *    inside a job that will not retry;
     *  - it does not pause for a human, which would park the run mid-drain;
     *  - it is not `unsafe-to-replay`. A node that says a second attempt is not a
     *    repeat of the first has earned its own job, its own claim, and its own
     *    attempt count — folding it into a neighbour's job to save a round trip
     *    is exactly the wrong trade.
     */
    /** @param list<string>|null $entryNodes */
    private function drainTarget(FlowGraph $graph, NodeKindRegistry $kinds, ?array $entryNodes = null): ?string
    {
        // Threaded rather than omitted: this frontier decides what to drain
        // inline, and a driver that forgot the live entry points here would pull
        // a node from a branch that never fired into the SAME job -- the exact
        // bug, surviving in the one path that skips the dispatcher.
        $frontier = Frontier::compute($graph, NodeClaims::state(NodeClaims::all($this->runKey)), $entryNodes);
        if (count($frontier['ready']) !== 1) {
            return null;
        }

        $node = $graph->node($frontier['ready'][0]);
        if ($node === null) {
            return null;
        }

        $kind = $node->type === null ? null : $kinds->get($node->type);
        if ($kind?->pausesForHuman !== null) {
            return null;
        }
        if (NodeRetryPolicy::isUnsafeToReplay($node, $kinds)) {
            return null;
        }
        if (NodeRetryPolicy::tries($node, $kinds, (array) config('fancy-flow')) !== 1) {
            return null;
        }

        return $node->id;
    }

    /**
     * How many extra nodes this job may absorb.
     *
     * Zero unless the job itself is single-attempt. A job that can be retried
     * must not carry nodes it already finished into the retry: the second
     * attempt would find them complete, step aside, and leave whatever it
     * claimed mid-chain held by a token nobody will come back for.
     */
    private function drainBudget(): int
    {
        if ($this->tries !== 1) {
            return 0;
        }

        return max(0, (int) config('fancy-flow.queue.drain_limit', 0));
    }

    public function failed(Throwable $e): void
    {
        $run = WorkflowRun::query()->where('run_key', $this->runKey)->first();
        if ($run === null) {
            return;
        }

        // Whichever node this job still holds is the one that died — normally
        // the one it was dispatched for, but a drained chain may have moved on.
        WorkflowRunNode::query()
            ->where('run_key', $this->runKey)
            ->where('owner', $this->token)
            ->where('status', WorkflowRunNode::CLAIMED)
            ->update([
                'status' => WorkflowRunNode::FAILED,
                'error' => $e->getMessage(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $run->forceFill(['status' => WorkflowRun::FAILED, 'error' => $e->getMessage()])->save();

        event(new WorkflowSettled($this->runKey, WorkflowSettled::FAILED, $e->getMessage()));
        event(new WorkflowFailed($this->runKey, $e->getMessage()));

        // Retries are exhausted, so the cohort moves on. A failed run does NOT
        // cancel the rest: the guard is what decides whether the next one still
        // has state to act on.
        app(TriggerCohort::class)->advance($run);
    }
}
