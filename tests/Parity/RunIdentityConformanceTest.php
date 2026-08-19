<?php

declare(strict_types=1);

use FancyFlow\Runtime\RunIdentity;
use ParticleAcademy\Conformance\Conformance;

/**
 * The three-runtime table for run/step identity, run against THIS side.
 *
 * `@particle-academy/fancy-flow` and `fancy-flow-py` read the identical rows
 * from the identical file. Idempotency is exactly the kind of contract prose
 * cannot keep honest: every implementation looks right in isolation, and the
 * failure only ever appears as a duplicate charge in somebody's ledger.
 *
 * ## The rows that carry the weight
 *
 * `0011` and `0012` are a pair and mean nothing apart: the same step on attempt
 * 1 and attempt 5 must produce the SAME key. An implementation that folds the
 * attempt number into the key passes every other row in this table and creates
 * a second charge on the first timeout in production.
 *
 * `0006` and `0007` are the other pair — a node literally named `a/b` at the
 * top level must not key the same as a node `b` inside an invocation of `a`.
 * Unescaped they spell the same string, so two unrelated writes share an
 * idempotency key and the provider deduplicates them into one.
 */

/** Dispatch one case to the implementation under test. */
function runIdentityCase(array $case): mixed
{
    $in = $case['input'];

    return match ($case['fn']) {
        'stepKey' => (new RunIdentity(
            $in['runKey'],
            $in['path'] ?? [],
            $in['attempt'] ?? 1,
        ))->stepKey($in['nodeId'], $in['occurrence'] ?? null),
        'isReplaySafe' => (new RunIdentity(
            'run_conformance',
            [],
            $in['attempt'],
            $in['firstAttemptAt'],
        ))->isReplaySafe($in['windowSeconds'], $in['now']),
        default => throw new RuntimeException(
            "case {$case['id']} calls unimplemented fn {$case['fn']}"
        ),
    };
}

it('loads the shared/flow-run-identity suite from the installed package', function () {
    // The vacuity guard. A suite that fails to load yields zero cases, and
    // "every case passed" over an empty list is indistinguishable from parity.
    $cases = Conformance::cases('shared/flow-run-identity');

    expect($cases)->toBeArray();
    expect(count($cases))->toBeGreaterThan(20);
    expect(array_column($cases, 'fn'))->toContain('stepKey', 'isReplaySafe');
});

it('matches the shared/flow-run-identity table on every case', function () {
    $summary = Conformance::runTable('shared/flow-run-identity', runIdentityCase(...));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail'
    );

    expect($failures)->toBe([], 'The runtimes disagree on: '.implode(
        ', ',
        array_column($failures, 'id')
    ));

    expect($summary['failed'])->toBe(0);
    expect($summary['passed'])->toBeGreaterThan(20);
});
