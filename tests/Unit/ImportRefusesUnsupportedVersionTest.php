<?php

declare(strict_types=1);

use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Workflow;

/**
 * `lenient` never covers the schema version.
 *
 * It used to. A lenient import turned an unsupported version into a warning and
 * carried on, and `FancyFlowManager::toGraph()` imports leniently on EVERY
 * `run()`, so a versionless graph ran here while a default import in the
 * TypeScript runtime, which is strict, refused the same document. The fancy-conformance
 * `flow/connector-runs` manifest recorded the split.
 *
 * `lenient` exists for unknown vocabulary: a kind this host has not registered.
 * A version is the format itself, and a runtime cannot honour a format it does
 * not know. The same rule holds in all three runtimes.
 */
function irvRegistry(): NodeKindRegistry
{
    return Builtin::register(new NodeKindRegistry(), withStructural: true);
}

function irvDoc(mixed $version, bool $withVersion = true): array
{
    $doc = [
        '$schema' => Workflow::SCHEMA_URL,
        'graph' => [
            'nodes' => [['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []]],
            'edges' => [],
        ],
    ];
    if ($withVersion) {
        $doc['version'] = $version;
    }

    return $doc;
}

it('refuses a document it cannot read, leniently or not', function (array $doc) {
    $result = Workflow::import($doc, lenient: true, registry: irvRegistry());

    expect($result->ok)->toBeFalse();
    expect($result->graph->nodes)->toBe([]);
    expect($result->issues)->toHaveCount(1);
    expect($result->errors())->toHaveCount(1);
    expect($result->errors()[0]->message)->toMatch('/^Unsupported workflow schema version: .* \(expected 1\)$/');
})->with([
    'no version at all' => [irvDoc(null, withVersion: false)],
    'a future version' => [irvDoc(2)],
    'the version as a string' => [irvDoc('1')],
    'a boolean' => [irvDoc(true)],
]);

it('answers a lenient import exactly as a strict one', function () {
    $lenient = Workflow::import(irvDoc(null, withVersion: false), lenient: true, registry: irvRegistry());
    $strict = Workflow::import(irvDoc(null, withVersion: false), registry: irvRegistry());

    expect($lenient->ok)->toBe($strict->ok);
    expect(array_map(fn ($i) => [$i->level, $i->message], $lenient->issues))
        ->toBe(array_map(fn ($i) => [$i->level, $i->message], $strict->issues));
});

it('reads version 1 written as 1.0, as the TypeScript and Python runtimes do', function () {
    // JSON `1.0` decodes to a float here. JavaScript cannot tell 1.0 from 1 at
    // all, so refusing it here alone would be a split of its own.
    $json = (string) preg_replace('/"version":1\b/', '"version":1.0', (string) json_encode(irvDoc(1)));
    expect(json_decode($json, true)['version'])->toBeFloat();

    expect(Workflow::import($json, registry: irvRegistry())->ok)->toBeTrue();
});

it('still softens an unknown kind to a warning in a version-1 document', function () {
    $doc = irvDoc(1);
    $doc['graph']['nodes'][] = ['id' => 'x', 'kind' => 'not_a_registered_kind', 'position' => ['x' => 200, 'y' => 0], 'config' => []];
    $doc['graph']['edges'][] = ['id' => 'e', 'source' => 't', 'target' => 'x'];

    $result = Workflow::import($doc, lenient: true, registry: irvRegistry());

    expect($result->ok)->toBeTrue();
    expect($result->graph->nodes)->toHaveCount(2);
    expect($result->errors())->toBe([]);
    expect(array_filter($result->warnings(), fn ($w) => str_contains($w->message, 'Unknown kind')))->not->toBe([]);
});
