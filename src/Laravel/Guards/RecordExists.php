<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Guards;

use FancyFlow\Contracts\TriggerGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * The guard almost every fan-out actually needs: does the record that fired the
 * trigger still exist?
 *
 * Registered as `record-exists`, so the common case needs no code at all:
 *
 * ```php
 * FancyFlow::dispatchCohort(
 *     [$enrichFlow, $archiveFlow, $notifyFlow],
 *     ['trigger' => $deal->toArray()],
 *     guard: ['name' => 'record-exists', 'args' => ['model' => Deal::class, 'id' => $deal->id]],
 * );
 * ```
 *
 * If `$archiveFlow` deletes the deal, `$notifyFlow` is skipped with
 * "App\Models\Deal 41 no longer exists" instead of notifying about nothing.
 *
 * Pass `column` to match on something other than the primary key.
 */
final class RecordExists implements TriggerGuard
{
    /** @param array<string,mixed> $args */
    public function passes(array $args, array $context): bool
    {
        $model = $this->model($args);
        $column = $args['column'] ?? null;

        $query = $column === null
            ? $model->newQuery()->whereKey($args['id'] ?? null)
            : $model->newQuery()->where((string) $column, $args['id'] ?? null);

        return $query->exists();
    }

    /** @param array<string,mixed> $args */
    public function reason(array $args, array $context): string
    {
        $id = is_scalar($args['id'] ?? null) ? (string) $args['id'] : '?';

        return trim((string) ($args['model'] ?? 'record'))." {$id} no longer exists";
    }

    /** @param array<string,mixed> $args */
    private function model(array $args): Model
    {
        $class = (string) ($args['model'] ?? '');

        // Throwing here is deliberate: TriggerCohort catches it and SKIPS the
        // run. A misconfigured guard must not silently degrade into "sure, go
        // ahead" — that is the exact behaviour cohorts exist to end.
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new \InvalidArgumentException(
                'record-exists needs a `model` arg naming an Eloquent model class; got '
                .($class === '' ? '(none)' : $class).'.'
            );
        }

        return new $class();
    }
}
