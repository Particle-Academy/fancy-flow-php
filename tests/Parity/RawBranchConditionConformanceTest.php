<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

/*
 * The shared rows pinning a RAW (non-string) `branch.condition`.
 *
 * `{"kind":"branch","config":{"condition":true}}` routed `true` HERE and `false`
 * in the TypeScript engine -- the same WorkflowSchema taking different routes,
 * which is the one thing the shared suites exist to prevent. This runtime was
 * already correct and TypeScript moved (fancy-flow 0.80.0); these rows are what
 * stop either side drifting back.
 *
 * Asserts `ok` plus WHICH NODES RAN. Both ports are wired to their own `output`
 * node, so the set of nodes that produced output IS the routing decision: `yes`
 * present and `no` absent can only mean the `true` port fired.
 *
 * The expected node list is read from the row rather than restated here. A
 * second copy of the answer in this file is a copy that can disagree with the
 * fixture, which is the failure these suites are supposed to remove rather than
 * reproduce.
 */

$rows = array_values(array_filter(
    Conformance::cases('flow/graph-runs'),
    static fn (array $case): bool => (bool) preg_match('/^003[2-6]-/', $case['id']),
));

it('loads all five shared raw-branch rows', function () use ($rows): void {
    echo "\nflow/graph-runs raw branch condition [php] -- fancy-conformance ".Conformance::version()."\n";
    expect(Conformance::version())->toBe('0.33.0');
    // A filter that silently matches nothing would turn every assertion below
    // into a pass over an empty set.
    expect(count($rows))->toBe(5);
});

it('routes a raw branch condition the way the shared row says', function (array $case): void {
    $registry = Builtin::register(new NodeKindRegistry, withStructural: true);
    $graph = Workflow::import($case['input']['schema'], lenient: true, registry: $registry)->graph;

    $result = (new FlowRunner)->run(
        $graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: $case['input']['initialInputs']),
    );

    expect($result->ok)->toBeTrue();

    $expected = array_keys($case['expected']['outputs']);
    sort($expected);

    $actual = array_keys($result->outputs);
    sort($actual);

    expect($actual)->toBe($expected);
})->with(array_combine(
    array_column($rows, 'id'),
    array_map(static fn (array $row): array => [$row], $rows),
));
