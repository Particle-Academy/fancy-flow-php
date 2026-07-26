<?php

declare(strict_types=1);

namespace FancyFlow\Contracts;

/**
 * A precondition re-checked immediately before a cohort run starts.
 *
 * When one event fires several workflows, an earlier run can invalidate the
 * state a later one was queued to act on — most sharply by deleting the record
 * that fired the trigger. The engine cannot know what "still valid" means for a
 * host's data, so it asks: the host registers a named guard, the cohort records
 * which guard and with what arguments, and the runner calls it just before each
 * run begins.
 *
 * Fail CLOSED. If a guard cannot tell whether the precondition holds, it should
 * say it does not: a skipped run is visible and recoverable, whereas a run that
 * proceeds over missing state completes successfully having done nothing, which
 * is the failure this whole mechanism exists to prevent.
 *
 * Guards are resolved by name from the container, never serialized as closures —
 * a queued job may run in a different process minutes later.
 *
 * ```php
 * class RecordStillExists implements TriggerGuard
 * {
 *     public function passes(array $args, array $context): bool
 *     {
 *         return Deal::whereKey($args['id'])->exists();
 *     }
 *
 *     public function reason(array $args, array $context): string
 *     {
 *         return "deal {$args['id']} no longer exists";
 *     }
 * }
 *
 * // config/fancy-flow.php
 * 'guards' => ['record-exists' => RecordStillExists::class],
 * ```
 */
interface TriggerGuard
{
    /**
     * Does the precondition still hold?
     *
     * @param  array<string,mixed>  $args     recorded on the cohort at dispatch
     * @param  array<string,mixed>  $context  `run_key`, `cohort_key`, `cohort_seq`
     */
    public function passes(array $args, array $context): bool;

    /**
     * Why it did not hold — recorded on the run as `skipped_reason`, so a human
     * reading the run list learns what changed rather than that nothing ran.
     *
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $context
     */
    public function reason(array $args, array $context): string;
}
