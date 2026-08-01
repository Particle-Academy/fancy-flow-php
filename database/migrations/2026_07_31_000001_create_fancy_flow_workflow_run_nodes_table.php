<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per node execution — the claim, and the checkpoint.
 *
 * ## Why this table exists
 *
 * A workflow ran as ONE queued job. `node_outputs` was written in a single
 * place, after the whole graph returned, which made the durability weaker than
 * it read:
 *
 *  - A worker killed mid-run (timeout, deploy, OOM, SIGKILL) never reached that
 *    write, so the retry resumed from the PREVIOUS checkpoint and re-ran every
 *    node that had completed. Nodes declaring `unsafe-to-replay` got re-run —
 *    `git_pr_open` opens a second pull request.
 *  - A run outliving `queue.retry_after` was released while still executing, so
 *    a second worker ran the same run concurrently.
 *  - One transient failure with `tries = 1` took the whole run down.
 *
 * Per-node jobs fix all three, but they introduce a race of their own: the
 * advance step recomputes the ready frontier after every node, so two overlapping
 * advances can both see the same node as ready. Without a claim, a fan-in node
 * executes twice.
 *
 * ## The claim
 *
 * `(run_key, node_id)` is UNIQUE. A worker claims a node by inserting that row;
 * losing the race is an insert conflict, which is a NO-OP rather than an error —
 * the other worker owns the node. That is the whole concurrency control, and it
 * is enforced by the database rather than by application timing.
 *
 * The row doubles as the checkpoint: `output` is written with the transition to
 * `completed`, in one statement, so a crash can never leave a node marked done
 * with no output or vice versa.
 *
 * ## Why `owner`
 *
 * A claim has to survive the claimer's OWN retry. Without an owner, a job
 * released back onto the queue would come back, find its own claim, and read it
 * as "someone else has this" — the node would never run and the run would stall
 * forever. `owner` is a token minted per DISPATCH and carried through every
 * attempt of that job, so "my claim" and "another worker's claim" are
 * distinguishable without guessing from timestamps.
 */
return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_run_nodes';
    }

    public function up(): void
    {
        // Idempotent: a host that published the migrations before 0.10 gets this
        // one from the package too, and running both must not explode.
        if (Schema::hasTable($this->table())) {
            return;
        }

        Schema::create($this->table(), function (Blueprint $table) {
            $table->id();

            // Not a foreign key to workflow_runs.id: runs are addressed by
            // run_key everywhere else in this package, and a host may prune runs
            // on a different schedule than it prunes node rows.
            $table->string('run_key')->index();
            $table->string('node_id');

            // claimed -> completed | skipped | paused | failed. Never back to
            // claimed: a retry of the same node reuses the row and bumps
            // `attempts`, so the history of how often a node was tried survives.
            $table->string('status')->default('claimed');

            // The dispatch token of the job holding the claim. See the class
            // docblock — this is what makes a job's own retry re-enter its claim
            // instead of deadlocking against it.
            $table->string('owner')->nullable();

            $table->json('output')->nullable();

            // The output ports this node's result ACTIVATED, captured from the
            // engine's own `node-output` events rather than recomputed. The
            // frontier is "which edges are live", and an edge is live exactly
            // when its source handle is in here — so this is what lets the
            // driver route a branch without a second copy of the engine's port
            // rules to drift away from it.
            $table->json('ports')->nullable();

            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(1);

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // THE claim. Everything above is bookkeeping; this line is the
            // reason the table exists.
            $table->unique(['run_key', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
