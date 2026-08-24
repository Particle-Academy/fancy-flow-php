<?php

declare(strict_types=1);

namespace FancyFlow\Testing;

use FancyFlow\Laravel\Jobs\AdvanceWorkflowJob;
use FancyFlow\Laravel\Jobs\RunNodeJob;
use Illuminate\Support\Facades\Queue;

/**
 * Drain a FAKED queue the way a worker would, and stop wherever you like.
 *
 * ## Do not drive a real worker in a test
 *
 * A real queue worker introduces a race that has nothing to do with the code
 * under test. Between enqueueing a job and a worker being able to see it there
 * is a gap, and a worker started with `--stop-when-empty` can find the queue
 * momentarily empty, exit having done nothing, and leave the assertion reading
 * `running`. The gap widens with load, so it appears only in longer suites —
 * which makes it look like flakiness and behave like a threshold.
 *
 * A consumer measured exactly that: their two failures passed in isolation on
 * two different package versions, passed on each half of their suite, passed
 * when the failing file was paired with every queue-using neighbour
 * individually, and failed only on the whole run. Their conclusion is the one
 * worth carrying: **a test that fails only under load is usually measuring the
 * harness.** They had carried those two as a known upstream bug for months.
 *
 * ## Why this is stronger than a real worker, not merely safer
 *
 * `queue.default = 'sync'` runs the whole advance → node → advance chain
 * inline, which is what most durable tests want — but it always runs the chain
 * to completion, so *"the worker died here"* cannot be expressed at all.
 *
 * With the queue faked, this drains it in the order a worker would — pending
 * node jobs, then the advances they queued — and stops after `$maxNodes` node
 * jobs. **Stopping IS the kill.** The run is left in exactly the state a worker
 * would have left it, which is how you assert an abandoned frontier, a
 * half-settled run, or a retry re-entering its own claim. A real worker cannot
 * be made to stop on demand at a chosen node; this can.
 *
 * ## Use
 *
 * ```php
 * Queue::fake();
 * QueuePump::reset();          // in beforeEach — the cursor is static
 *
 * FancyFlow::dispatch($schema);
 *
 * QueuePump::drain();          // run it out
 * QueuePump::drain(maxNodes: 2); // ...or kill the worker after two nodes
 * ```
 */
final class QueuePump
{
    /**
     * How far each job class has been drained.
     *
     * `Queue::pushed()` returns everything pushed since the fake began, so the
     * cursor is what stops an already-executed job being run twice on the next
     * call — which would double every side effect and make a partial drain
     * impossible to resume.
     *
     * @var array<class-string, int>
     */
    private static array $cursor = [];

    /** Forget how far we have drained. Call this per test. */
    public static function reset(): void
    {
        self::$cursor = [];
    }

    /**
     * Run pending jobs in worker order until the queue is quiet, or until
     * `$maxNodes` node jobs have run.
     *
     * Node jobs are drained before advances on each pass, because that is the
     * order a worker sees them: an advance is queued BY the node job that
     * settled, so honouring the reverse would run a frontier that has not been
     * computed yet.
     *
     * @return int how many node jobs were executed
     */
    public static function drain(int $maxNodes = PHP_INT_MAX): int
    {
        $ran = 0;

        while (true) {
            $progress = false;

            foreach ([RunNodeJob::class, AdvanceWorkflowJob::class] as $class) {
                $jobs = Queue::pushed($class)->values();

                while ((self::$cursor[$class] ?? 0) < $jobs->count()) {
                    // The kill. Returning here leaves every remaining job
                    // pending and the run exactly as an interrupted worker
                    // would have left it.
                    if ($class === RunNodeJob::class && $ran >= $maxNodes) {
                        return $ran;
                    }

                    $index = self::$cursor[$class] ?? 0;
                    self::$cursor[$class] = $index + 1;

                    app()->call([$jobs[$index], 'handle']);
                    $progress = true;

                    if ($class === RunNodeJob::class) {
                        $ran++;
                    }
                }
            }

            // A pass that executed nothing means the queue is quiet. Looping
            // again would spin, since draining is what produces new jobs.
            if (! $progress) {
                return $ran;
            }
        }
    }
}
