<?php

declare(strict_types=1);

use ParticleAcademy\Conformance\Conformance;
use FancyFlow\Registry\Builtin;

/**
 * Parity of SURFACE, not behaviour.
 *
 * Every other conformance table here pins what the engine DOES. This one pins
 * what a kind DECLARES — and nothing pinned that until four capabilities had
 * been found present in one runtime and absent in the others: `graph.inputs`
 * dropped on import, `sideEffects` declared by nothing, the conformance Python
 * loader never published, and `outputShape` existing only in TypeScript.
 *
 * In every one of those, **absent reads as a legitimate answer**, so nothing
 * reported the gap. This table is what makes a fifth loud.
 */

/**
 * @param  array<string,mixed> $case
 * @return array{outputShape: list<string>|string|null, emits: string|null}
 */
function runKindDeclarationSurfaceCase(array $case): array
{
    $registry = Builtin::register(null, true);
    $kind = $registry->get((string) $case['input']['kind']);

    expect($kind)->not->toBeNull("builtin `{$case['input']['kind']}` is not registered");

    /** @var array<string,mixed> $config */
    $config = $case['input']['config'] ?? [];

    // A config-dependent shape reports the MARKER, never a resolved list: the
    // table asks what the kind DECLARES, and "depends on config" is the
    // declaration. Resolving it here would compare four runtimes' answers to a
    // question each was asked with different config.
    $shape = $kind->hasDynamicOutputShape()
        ? 'dynamic'
        : $kind->outputShapeFor($config);

    if (is_array($shape)) {
        // A SET, not an ordered list. These come out of maps, and the Rust twin
        // inserts `count` before `items` where the others do the reverse — so
        // asserting order would report a divergence that is not one, and a
        // fixture that cries wolf is one nobody reads.
        $shape = array_column($shape, 'path');
        sort($shape);
    }

    return [
        'outputShape' => $shape,
        'emits' => $kind->emitsFor($config),
    ];
}

it('matches the flow/kind-declaration-surface table on every case', function (): void {
    $summary = Conformance::runTable('flow/kind-declaration-surface', runKindDeclarationSurfaceCase(...));

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

    // The vacuity floor. A table that loaded zero rows and reported zero
    // failures is indistinguishable from a passing one, and that is exactly the
    // shape of failure this whole suite exists to catch.
    expect($summary['passed'])->toBeGreaterThanOrEqual(20);
});
