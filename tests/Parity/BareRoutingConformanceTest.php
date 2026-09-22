<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

$rows = array_values(array_filter(Conformance::cases('flow/graph-runs'),
    static fn (array $case): bool => (str_contains($case['id'], '-bare-') || str_contains($case['id'], '-unclosed-'))));

it('loads all eight shared routing refusal rows', function () use ($rows): void {
    echo "\nflow/graph-runs routing refusals [php] -- fancy-conformance ".Conformance::version()."\n";
    expect(Conformance::version())->toBe('0.32.0');
    expect(count($rows))->toBe(8);
});

it('rejects the shared routing expression before downstream nodes run', function (array $case): void {
    $registry = Builtin::register(new NodeKindRegistry, withStructural: true);
    $graph = Workflow::import($case['input']['schema'], lenient: true, registry: $registry)->graph;
    $result = (new FlowRunner)->run($graph, Builtin::executors(),
        options: new RunOptions(initialInputs: $case['input']['initialInputs']));
    expect(['ok' => $result->ok, 'error' => $result->error])->toBe($case['expected']);
    expect(array_keys($result->outputs))->toBe(['t']);
})->with(array_combine(array_column($rows, 'id'), array_map(static fn (array $row): array => [$row], $rows)));
