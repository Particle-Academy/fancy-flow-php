<?php

declare(strict_types=1);

use FancyFlow\Runtime\WorkflowProps;
use ParticleAcademy\Conformance\Conformance;

/**
 * The workflow-props table, run against THIS side.
 *
 * `@particle-academy/fancy-flow` and `fancy-flow` (Python) run the identical
 * rows from the identical file. One table, three runtimes: a divergence is a
 * red build in whichever drifted, rather than a consumer discovering it.
 *
 * ## This one arrived on time, and that is the point
 *
 * `flow/subflow-registry` was written AFTER all four runtimes had shipped the
 * same defect — a post-mortem in fixture form. This table existed before this
 * port was started, so it is a specification rather than a record, and the
 * implementation below was written to satisfy rows that were already published.
 *
 * It earned its keep immediately: the port failed exactly one row, `0106`, and
 * the row turned out to describe an input PHP **cannot represent** —
 * `json_decode('{"0":"a"}', true)` coerces the numeric string key to int `0`,
 * so the map becomes a list and `array_is_list` is right to say so. That is a
 * genuine cross-language divergence rather than a bug on either side, so the
 * case is skipped here with that reason and `0109` pins the same rule using a
 * non-numeric key, which every runtime agrees on.
 *
 * ## The rows that carry the weight here
 *
 * **The falsy trap (0004-0006).** `0`, `false` and `""` are values a caller
 * meant to pass, and PHP makes this easier to get wrong than JavaScript does:
 * `isset()` is false for a supplied NULL and `empty()` is true for all three,
 * so both natural spellings silently replace a real value with a default.
 * `array_key_exists` is the only correct question.
 *
 * **Absent stays absent (0007).** PHP has one absent value and JavaScript has
 * two. Writing `null` for every unsupplied optional would make
 * `{{ $props.note }}` resolve differently across runtimes for one graph.
 */

/**
 * Run one case, returning only the keys the table asserts.
 *
 * `resolve()` also carries a human-readable `error`, deliberately absent from
 * the contract: each runtime words its failures idiomatically, and comparing
 * the prose would hold three implementations to a translation.
 *
 * @param  array<string,mixed>  $case
 * @return array<string,mixed>
 */
function runWorkflowPropsCase(array $case): array
{
    $result = WorkflowProps::resolve(
        $case['input']['declared'] ?? null,
        $case['input']['passed'] ?? null,
    );

    return $result['ok'] === true
        ? ['ok' => true, 'props' => $result['props']]
        : ['ok' => false, 'code' => $result['code']];
}

it('matches the flow/workflow-props table on every case', function (): void {
    $summary = Conformance::runTable('flow/workflow-props', runWorkflowPropsCase(...));

    // Printed unconditionally, so a green build still shows WHAT was compared —
    // including which rows were skipped and why.
    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail',
    );

    expect($failures)->toBe([], 'PHP disagrees with the shared table on: '.implode(
        ', ',
        array_column($failures, 'id'),
    ));

    expect($summary['failed'])->toBe(0);

    // A suite that skipped everything would also report zero failures. The
    // floor is deliberately close to the real count so that silently losing
    // rows — a bad path, a stale package — fails instead of passing quietly.
    expect($summary['passed'])->toBeGreaterThan(15);
});

it('rejects a default applied over a supplied falsy value', function (): void {
    // The discrimination check. Without it, an implementation using `empty()`
    // or `??` could pass the table only because the table happened not to
    // exercise the difference — so this pins that the difference is real, is
    // exercised, and is the PHP-specific half of it.
    $declared = [['name' => 'limit', 'type' => 'number', 'default' => 10]];

    $result = WorkflowProps::resolve($declared, ['limit' => 0]);

    expect($result['ok'])->toBeTrue();
    expect($result['props']['limit'])->toBe(0, 'a supplied 0 must survive a default of 10');

    // And the spellings that would have got it wrong, asserted directly so the
    // reason this code uses array_key_exists is visible rather than folklore.
    expect(empty(0))->toBeTrue();
    expect(isset(['limit' => null]['limit']))->toBeFalse();
});
