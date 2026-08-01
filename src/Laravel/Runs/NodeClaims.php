<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\Laravel\Models\WorkflowRunNode;
use Illuminate\Support\Carbon;

/**
 * The claim. One node of one run belongs to exactly one worker, and the
 * DATABASE decides which — not the order two jobs happened to reach a check.
 *
 * ## Why a claim is needed at all
 *
 * `AdvanceWorkflowJob` recomputes the ready frontier after every node. Two
 * advances that overlap — a fan-in whose last two inputs land together, a
 * duplicate dispatch, a job released by `queue.retry_after` while still running
 * — both compute the same node as ready and both dispatch it. Without a claim
 * that node executes twice, which for `git_pr_open` means two pull requests.
 *
 * ## How it is enforced
 *
 * `(run_key, node_id)` carries a unique constraint, so claiming is an
 * `insertOrIgnore`. Exactly one insert can win. The loser is told by the
 * constraint, not by re-reading and hoping the world did not move between the
 * read and the write, which is the shape every timing-based "check then act"
 * eventually fails at.
 *
 * **A lost race is a NO-OP, never an error.** The other worker owns the node
 * and will carry the run forward; there is nothing wrong to report.
 *
 * ## The one subtlety: a job's own retry
 *
 * A released job comes back and finds a claim — its own. Read naively that is
 * "someone else has this", the job returns, nobody ever runs the node, and the
 * run stalls forever. So the claim records the `owner` token minted at dispatch
 * and carried through every attempt: same token means reclaim and bump
 * `attempts`, different token means step aside.
 */
final class NodeClaims
{
    /** The claim was inserted — this worker owns the node. */
    public const WON = 'won';

    /** The claim already existed and belongs to this job. Its own retry. */
    public const RECLAIMED = 'reclaimed';

    /** Another worker owns it, or it is already settled. Do nothing. */
    public const LOST = 'lost';

    /**
     * Attempt to claim `$nodeId` for `$owner`.
     *
     * @return self::WON|self::RECLAIMED|self::LOST
     */
    public static function claim(string $runKey, string $nodeId, string $owner): string
    {
        $now = Carbon::now();

        $inserted = WorkflowRunNode::query()->insertOrIgnore([
            'run_key' => $runKey,
            'node_id' => $nodeId,
            'status' => WorkflowRunNode::CLAIMED,
            'owner' => $owner,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return self::WON;
        }

        $row = self::row($runKey, $nodeId);

        // Settled, or held by someone else. Either way this job has no business
        // executing the node.
        if ($row === null || $row->status !== WorkflowRunNode::CLAIMED || $row->owner !== $owner) {
            return self::LOST;
        }

        $row->forceFill([
            'attempts' => $row->attempts + 1,
            'claimed_at' => $now,
        ])->save();

        return self::RECLAIMED;
    }

    /**
     * Record a node's result and release the claim, in one write.
     *
     * `output` and `ports` land with the status transition rather than after it,
     * so there is no window in which a node reads as done with no checkpoint —
     * the exact window the single-job driver left open for a whole graph.
     *
     * @param list<string> $ports the output ports the result activated
     */
    public static function complete(string $runKey, string $nodeId, mixed $output, array $ports): void
    {
        self::settle($runKey, $nodeId, [
            'status' => WorkflowRunNode::COMPLETED,
            'output' => $output,
            'ports' => array_values($ports),
            'error' => null,
        ]);
    }

    /**
     * Record that a node will never run: every incoming edge was dead, or it is
     * an annotation. No output, no ports — a skipped node publishes nothing, and
     * inventing an output for it would route branches the engine left dark.
     *
     * Written with `insertOrIgnore` because skips are computed, not claimed: a
     * concurrent advance may have recorded the same one already.
     */
    public static function skip(string $runKey, string $nodeId): void
    {
        $now = Carbon::now();

        $inserted = WorkflowRunNode::query()->insertOrIgnore([
            'run_key' => $runKey,
            'node_id' => $nodeId,
            'status' => WorkflowRunNode::SKIPPED,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'completed_at' => $now,
        ]);

        if ($inserted === 0) {
            // Already there. A node that genuinely ran outranks a computed skip,
            // so only an unsettled row is overwritten.
            WorkflowRunNode::query()
                ->where('run_key', $runKey)
                ->where('node_id', $nodeId)
                ->whereNotIn('status', WorkflowRunNode::SETTLED)
                ->update(['status' => WorkflowRunNode::SKIPPED, 'completed_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Record a node that was already complete before this table existed.
     *
     * A run checkpointed by the single-job driver carries its completed nodes in
     * the run's `node_outputs` blob. Adopting them means a run parked under 0.9
     * resumes on 0.10 from where it stopped, rather than starting the graph
     * again — which for an `unsafe-to-replay` node is the whole bug, arriving by
     * way of the upgrade that was meant to fix it.
     *
     * @param list<string> $ports
     */
    public static function adopt(string $runKey, string $nodeId, mixed $output, array $ports): void
    {
        $now = Carbon::now();

        WorkflowRunNode::query()->insertOrIgnore([
            'run_key' => $runKey,
            'node_id' => $nodeId,
            'status' => WorkflowRunNode::COMPLETED,
            'output' => json_encode($output),
            'ports' => json_encode(array_values($ports)),
            'attempts' => 1,
            'claimed_at' => $now,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Park a node on a human wait. The row is cleared when the run resumes. */
    public static function pause(string $runKey, string $nodeId): void
    {
        self::settle($runKey, $nodeId, ['status' => WorkflowRunNode::PAUSED]);
    }

    /** Attempts exhausted — this node is why the run fails. */
    public static function fail(string $runKey, string $nodeId, string $error): void
    {
        self::settle($runKey, $nodeId, ['status' => WorkflowRunNode::FAILED, 'error' => $error]);
    }

    /**
     * Drop the paused rows for a run so their nodes can be claimed again.
     *
     * Called when a run resumes: the recorded decision / submission is fed in as
     * an initial input, and the node has to actually execute to consume it.
     */
    public static function clearPaused(string $runKey): void
    {
        WorkflowRunNode::query()
            ->where('run_key', $runKey)
            ->where('status', WorkflowRunNode::PAUSED)
            ->delete();
    }

    /**
     * Every node row for a run, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,WorkflowRunNode>
     */
    public static function all(string $runKey)
    {
        return WorkflowRunNode::query()->where('run_key', $runKey)->orderBy('id')->get();
    }

    public static function row(string $runKey, string $nodeId): ?WorkflowRunNode
    {
        return WorkflowRunNode::query()
            ->where('run_key', $runKey)
            ->where('node_id', $nodeId)
            ->first();
    }

    /**
     * The completed nodes' outputs, keyed by node id — what the engine resumes
     * from, and what the run reports when it finishes.
     *
     * Skipped nodes are deliberately absent: `resumeOutputs` republishes what it
     * is given, so a skipped node listed here would light up a branch that never
     * ran.
     *
     * @param  iterable<WorkflowRunNode>  $rows
     * @return array<string,mixed>
     */
    public static function outputs(iterable $rows): array
    {
        $outputs = [];
        foreach ($rows as $row) {
            if ($row->status === WorkflowRunNode::COMPLETED) {
                $outputs[$row->node_id] = $row->output;
            }
        }

        return $outputs;
    }

    /**
     * The frontier's view of a run: node id => status + activated ports.
     *
     * @param  iterable<WorkflowRunNode>  $rows
     * @return array<string,array{status:string,ports:list<string>}>
     */
    public static function state(iterable $rows): array
    {
        $state = [];
        foreach ($rows as $row) {
            $state[$row->node_id] = [
                'status' => $row->status,
                'ports' => is_array($row->ports) ? array_values($row->ports) : [],
            ];
        }

        return $state;
    }

    /** @param array<string,mixed> $values */
    private static function settle(string $runKey, string $nodeId, array $values): void
    {
        $row = self::row($runKey, $nodeId);
        if ($row === null) {
            return;
        }

        $row->forceFill($values + ['completed_at' => Carbon::now()])->save();
    }
}
