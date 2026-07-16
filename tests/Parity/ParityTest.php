<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Workflow;

/**
 * The parity suite. Every fixture in `fixtures/` is a WorkflowSchema +
 * initialInputs with a baked-in golden `{ok, outputs}`. Running it through the
 * PHP engine + deterministic default executors must reproduce that result
 * exactly. The same JSON fixtures are the contract a Node harness asserts
 * against (`fancy-flow`'s engine), so a divergence between the two runtimes
 * surfaces as a failing fixture on one side.
 */

/** @return array<string,mixed> */
function loadFixture(string $file): array
{
    $doc = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    expect($doc)->toBeArray();

    return $doc;
}

/** @return array{0:bool,1:array<string,mixed>,2:?string} */
function runFixture(array $doc): array
{
    $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
    $import = Workflow::import($doc['schema'], lenient: true, registry: $registry);
    $result = (new FlowRunner())->run(
        $import->graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: $doc['initialInputs'] ?? []),
    );

    return [$result->ok, $result->outputs, $result->error];
}

$files = glob(__DIR__.'/fixtures/*.json') ?: [];

it('has a full set of fixtures', function () use ($files) {
    expect(count($files))->toBeGreaterThanOrEqual(22);
});

foreach ($files as $file) {
    $name = basename($file, '.json');

    it("reproduces the golden result for fixture {$name}", function () use ($file) {
        $doc = loadFixture($file);
        [$ok, $outputs, $error] = runFixture($doc);

        expect($ok)->toBe($doc['expected']['ok']);

        if (isset($doc['expected']['errorContains'])) {
            expect($error)->toContain($doc['expected']['errorContains']);
        }

        if (array_key_exists('outputs', $doc['expected'])) {
            expect($outputs)->toEqual($doc['expected']['outputs']);
        }
    });
}
