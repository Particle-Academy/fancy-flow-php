<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Contracts\TriggerGuard;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Contracts\Container\Container;

/**
 * The runs one trigger event fired, treated as a group instead of N strangers.
 *
 * ## The problem
 *
 * Fan one event out to several workflows and you get several independent queue
 * jobs. Nothing orders them, and nothing stops the second from running after the
 * first has deleted the record they were both fired for. The second run then
 * completes over missing input and reports **success** — the failure mode this
 * engine treats as the worst kind, because nothing surfaces it.
 *
 * ## What a cohort does
 *
 * - **Orders.** Runs carry `cohort_seq`, assigned at dispatch from the order the
 *   caller declared. Queue arrival stops deciding anything.
 * - **Serializes.** Only the head is enqueued; each run enqueues its successor
 *   when it settles. Run N+1 therefore observes run N's side effects on purpose
 *   rather than by luck.
 * - **Guards.** Immediately before each run starts, its {@see TriggerGuard} is
 *   re-checked. If the precondition has gone, the run is SKIPPED with a reason
 *   instead of executing on nothing — and the cohort continues to the next.
 *
 * The payload was already safe: `initial_inputs` is persisted per run at
 * dispatch, so an earlier run mutating the *record* never rewrites a later run's
 * *payload*. The guard covers the other half — the record itself.
 *
 * ## Policies
 *
 * - `serial-guarded` (default) — ordered, one at a time, guard before each.
 * - `serial` — ordered, one at a time, no guard.
 * - `parallel` — the old behaviour, all enqueued at once. Ordering is incidental
 *   and collisions are the caller's problem; kept because plenty of fan-outs
 *   genuinely don't touch shared state.
 */
final class TriggerCohort
{
    public function __construct(private readonly Container $container) {}

    /**
     * Should this run start? Records the outcome on the run either way.
     *
     * Fails CLOSED: an unresolvable or throwing guard skips the run. A skipped
     * run is visible and re-runnable; a run that proceeded over deleted state
     * looks like a success and is neither.
     */
    public function allows(WorkflowRun $run): bool
    {
        $guard = $run->guard;
        if (! is_array($guard) || ($guard['name'] ?? null) === null) {
            return true;
        }
        if ($run->cohort_policy === WorkflowRun::POLICY_SERIAL) {
            return true; // ordered, but the caller opted out of checking
        }

        $name = (string) $guard['name'];
        $args = is_array($guard['args'] ?? null) ? $guard['args'] : [];
        $context = [
            'run_key' => $run->run_key,
            'cohort_key' => $run->cohort_key,
            'cohort_seq' => $run->cohort_seq,
        ];

        try {
            $instance = $this->resolveGuard($name);
            if ($instance->passes($args, $context)) {
                return true;
            }
            $this->skip($run, $instance->reason($args, $context));
        } catch (\Throwable $e) {
            $this->skip($run, "guard [{$name}] could not be evaluated: ".$e->getMessage());
        }

        return false;
    }

    /**
     * Enqueue the next run in this run's cohort, if any.
     *
     * Called when a run settles — completed, failed, or skipped. A run parked on
     * a human wait has NOT settled and must not advance the cohort, or the whole
     * point (later runs see earlier ones' effects) is lost while a person is
     * still deciding.
     */
    public function advance(WorkflowRun $run): ?WorkflowRun
    {
        if ($run->cohort_key === null || $run->cohort_policy === WorkflowRun::POLICY_PARALLEL) {
            return null;
        }

        $next = WorkflowRun::query()
            ->where('cohort_key', $run->cohort_key)
            ->where('cohort_seq', '>', $run->cohort_seq ?? 0)
            ->where('status', WorkflowRun::PENDING)
            ->orderBy('cohort_seq')
            ->first();

        if ($next !== null) {
            RunWorkflowJob::enqueue($next);
        }

        return $next;
    }

    private function skip(WorkflowRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => WorkflowRun::SKIPPED,
            'skipped_reason' => $reason,
        ])->save();
    }

    /** Guards available without any host registration. */
    private const BUILTIN = ['record-exists' => Guards\RecordExists::class];

    private function resolveGuard(string $name): TriggerGuard
    {
        $map = (array) config('fancy-flow.guards', []);
        // Host registrations win, so `record-exists` can be swapped for one that
        // knows about soft deletes or tenancy without renaming every cohort.
        $binding = $map[$name] ?? self::BUILTIN[$name] ?? $name;
        $instance = $this->container->make($binding);

        if (! $instance instanceof TriggerGuard) {
            throw new \RuntimeException(
                "[{$name}] resolved to ".get_debug_type($instance).', which is not a '.TriggerGuard::class.'.'
            );
        }

        return $instance;
    }
}
