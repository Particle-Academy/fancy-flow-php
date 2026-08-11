<?php

declare(strict_types=1);

use FancyFlow\Nodes\Support\Expr;
use ParticleAcademy\Conformance\Conformance;

/**
 * The `{{ }}` parity table, run against THIS side.
 *
 * `@particle-academy/fancy-flow` runs the identical rows from the identical
 * file. That is the whole mechanism: both runtimes read one table, so a
 * divergence is a red build in whichever one drifted rather than a support
 * ticket months later.
 *
 * ## Why this suite arrived so late
 *
 * `Expr` has resolved expressions here since it shipped, and had **no
 * TypeScript twin at all** until fancy-flow 0.43.0. Conformance could not have
 * caught that, and the reason is structural rather than an oversight: a fixture
 * table compares two implementations and reports that they DISAGREE. An absent
 * implementation has nothing to run the rows against, so there is no failure to
 * notice. It guards drift, not absence.
 *
 * The second reason was more ordinary — nothing depended on the conformance
 * package, so none of its suites ran anywhere. This file and its Node twin are
 * the first two consumers.
 *
 * ## The rows that carry the weight
 *
 * Truthiness. `"0"`, `"false"` and `[]` are all truthy in JavaScript and falsy
 * here, and a branch node reading a form value or a JSON body hits every one of
 * them. A port that forwards to native truthiness fails exactly cases
 * 0013-0015, which is the signal this table exists to produce.
 *
 * ## Loaded from the installed package
 *
 * Via `Conformance::cases()`, not a relative path to a sibling checkout. The
 * conformance repo's own runner notes record why: its two older parity
 * harnesses hard-coded `../../<repo>/src/`, so they worked in exactly one
 * directory layout and silently no-op'd everywhere else — CI included.
 */

/** Dispatch one case to the implementation under test. */
function runExprCase(array $case): mixed
{
    return match ($case['fn']) {
        'evaluateExpression' => Expr::evaluate(
            $case['input']['template'],
            $case['input']['context'] ?? [],
        ),
        'truthy' => Expr::truthy($case['input']['value']),
        default => throw new RuntimeException(
            "case {$case['id']} calls unimplemented fn {$case['fn']}"
        ),
    };
}

it('loads the shared/expr suite from the installed package', function () {
    // The vacuity guard, and the one that matters most here. A suite that fails
    // to load yields zero cases, and "every case passed" over an empty list is
    // indistinguishable from parity. This asserts the table is really present
    // and really populated before anything is compared.
    $cases = Conformance::cases('shared/expr');

    expect($cases)->toBeArray();
    expect(count($cases))->toBeGreaterThan(15);
    expect(array_column($cases, 'fn'))->toContain('evaluateExpression', 'truthy');
});

it('matches the shared/expr table on every case', function () {
    $summary = Conformance::runTable('shared/expr', runExprCase(...));

    // Printed unconditionally — the conformance runner README asks for it, so a
    // green build still shows WHAT was compared rather than only that nothing
    // failed.
    echo "\n".Conformance::formatSummary($summary)."\n";

    // `status` is 'pass' | 'fail' | 'skip' — there is no boolean `ok` per row,
    // and reading one would treat every result as a failure.
    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail'
    );

    expect($failures)->toBe([], 'PHP and TypeScript disagree on: '.implode(
        ', ',
        array_column($failures, 'id')
    ));

    expect($summary['failed'])->toBe(0);
    // A suite that skipped everything would report zero failures too.
    expect($summary['passed'])->toBeGreaterThan(15);
});

it('disagrees with native PHP truthiness where the table says it should', function () {
    // The discrimination check. Without it, a `truthy` that simply forwarded to
    // `(bool)` would pass the table only because the table happened not to
    // exercise the difference — so this pins that the difference is real and
    // exercised.
    expect(Expr::truthy('false'))->toBeFalse();
    expect((bool) 'false')->toBeTrue();

    expect(Expr::truthy('0'))->toBeFalse();
    expect(Expr::truthy([]))->toBeFalse();
    expect(Expr::truthy('no thanks'))->toBeTrue();
});
