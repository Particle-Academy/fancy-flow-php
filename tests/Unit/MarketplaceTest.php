<?php

declare(strict_types=1);

use FancyFlow\Marketplace\FixtureRunner;
use FancyFlow\Marketplace\NodeManifest;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;

function validManifest(array $overrides = []): array
{
    return array_merge([
        'schemaVersion' => NodeManifest::SCHEMA_VERSION,
        'name' => '@acme/fancy-flow-salesforce',
        'kind' => '@acme/salesforce_upsert',
        'fancyFlow' => '>=0.15.0',
        'runtimes' => ['ts' => 'dist/executor.js', 'php' => 'acme/fancy-flow-salesforce:^0.1'],
        'fixtures' => 'fixtures/salesforce_upsert.json',
    ], $overrides);
}

/** @return list<string> */
function fields(array $problems): array
{
    return array_values(array_map(static fn (array $p) => $p['field'], $problems));
}

// ── Manifest ───────────────────────────────────────────────────────────────

it('accepts a complete manifest', function () {
    expect(NodeManifest::validate(validManifest()))->toBe([]);
    expect(NodeManifest::isValid(validManifest()))->toBeTrue();
});

it('reports every problem at once, not just the first', function () {
    // An author fixing a package wants the whole list — one error per run turns
    // a five-minute fix into five round trips.
    $problems = NodeManifest::validate(['schemaVersion' => 1]);

    expect(fields($problems))->toContain('name', 'kind', 'fancyFlow', 'runtimes', 'fixtures');
});

it('rejects a bare, un-namespaced kind id', function () {
    // The one mistake that cannot be fixed later: the ambiguous string is
    // already written into saved documents.
    $problems = NodeManifest::validate(validManifest(['kind' => 'salesforce_upsert']));

    expect(NodeManifest::isValid(validManifest(['kind' => 'salesforce_upsert'])))->toBeFalse();
    expect($problems[0]['message'])->toContain('namespaced');
});

it('warns rather than fails when a package claims the first-party scope', function () {
    $manifest = validManifest(['kind' => '@particle-academy/salesforce_upsert']);
    $problems = NodeManifest::validate($manifest);

    expect(NodeManifest::isValid($manifest))->toBeTrue();
    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('warning');
});

it('refuses an author-set verified flag', function () {
    $problems = NodeManifest::validate(validManifest(['verified' => true]));

    expect(fields($problems))->toContain('verified');
    expect(NodeManifest::isValid(validManifest(['verified' => true])))->toBeFalse();
});

it('requires fixtures — the publish gate', function () {
    $manifest = validManifest();
    unset($manifest['fixtures']);

    expect(fields(NodeManifest::validate($manifest)))->toContain('fixtures');
});

it('rejects a node implementing no runtime', function () {
    $problems = NodeManifest::validate(validManifest(['runtimes' => []]));

    expect($problems[0]['message'])->toContain('cannot execute anywhere');
});

it('stops at an unknown schema version instead of guessing the rest', function () {
    $problems = NodeManifest::validate(['schemaVersion' => 99]);

    expect($problems)->toHaveCount(1);
    expect($problems[0]['message'])->toContain('Upgrade fancy-flow');
});

it('catches the TS-only package on a PHP host', function () {
    // The exact gap MOIC hit: the node installs, appears in the palette, and
    // then cannot run — with nothing visible beforehand.
    $problems = NodeManifest::checkRuntimeSupport(
        ['kind' => '@acme/x', 'runtimes' => ['ts' => 'dist/x.js']],
        ['php'],
    );

    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('error');
    expect($problems[0]['message'])->toContain('executes on php');
});

it('passes when the node implements every runtime the host executes on', function () {
    expect(NodeManifest::checkRuntimeSupport(validManifest(), ['ts', 'php']))->toBe([]);
});

it('warns, not errors, about an unwired capability', function () {
    $problems = NodeManifest::checkCapabilities(
        ['kind' => '@acme/x', 'capabilities' => ['llm']],
        ['llm' => false],
    );

    expect($problems[0]['level'])->toBe('warning');
});

// ── Fixture file validation ────────────────────────────────────────────────

it('rejects an empty case list', function () {
    expect(FixtureRunner::validateFile(['kind' => '@acme/x', 'cases' => []])[0])
        ->toContain('empty fixture file proves nothing');
});

it('rejects a case that asserts nothing', function () {
    $problems = FixtureRunner::validateFile([
        'kind' => '@acme/x',
        'cases' => [['name' => 'n', 'expect' => []]],
    ]);

    expect($problems[0])->toContain('must assert at least one');
});

it('catches a fixture file for a different kind than the manifest declares', function () {
    $problems = FixtureRunner::validateFile(
        ['kind' => '@acme/y', 'cases' => [['name' => 'n', 'expect' => ['ports' => ['out']]]]],
        '@acme/x',
    );

    expect($problems[0])->toContain('manifest declares');
});

it('accepts a well-formed file', function () {
    expect(FixtureRunner::validateFile(
        ['kind' => '@acme/x', 'cases' => [['name' => 'n', 'expect' => ['ports' => ['out']]]]],
        '@acme/x',
    ))->toBe([]);
});

// ── Running fixtures asserts reachability, not intent ──────────────────────

it('passes when only the chosen port reaches a downstream node', function () {
    $file = [
        'kind' => 'switch_case',
        'cases' => [[
            'name' => 'routes to b',
            'ports' => ['a', 'b'],
            'expect' => ['ports' => ['b']],
        ]],
    ];

    $result = (new FixtureRunner())->run($file, static fn (ExecutionContext $ctx) => Port::only('b'));

    expect($result['ok'])->toBeTrue();
    expect($result['passed'])->toBe(1);
});

it('FAILS when the node records a port that has no reachable downstream', function () {
    // The 0.9.0 lesson, encoded: a test reading back the recorded port stays
    // green here. Only a probe on the edge catches it.
    $file = [
        'kind' => 'switch_case',
        'cases' => [[
            'name' => 'claims c',
            'ports' => ['a', 'b'],
            'expect' => ['ports' => ['c']],
        ]],
    ];

    $result = (new FixtureRunner())->run($file, static fn (ExecutionContext $ctx) => Port::only('c'));

    expect($result['ok'])->toBeFalse();
    expect($result['failures'][0]['message'])->toContain('expected these ports to reach a downstream node');
});

it('asserts a pause', function () {
    $file = [
        'kind' => 'user_input',
        'cases' => [[
            'name' => 'waits',
            'expect' => ['pause' => ['awaiting' => 'input', 'detail' => ['fields' => ['email']]]],
        ]],
    ];

    $result = (new FixtureRunner())->run(
        $file,
        static fn (ExecutionContext $ctx) => $ctx->pauseForHuman('input', ['fields' => ['email']]),
    );

    expect($result['ok'])->toBeTrue();
});

it('does not accept a failure as a pause', function () {
    $file = ['kind' => 'user_input', 'cases' => [['name' => 'waits', 'expect' => ['pause' => ['awaiting' => 'input']]]]];

    $result = (new FixtureRunner())->run($file, static function (ExecutionContext $ctx) {
        throw new RuntimeException('database is down');
    });

    expect($result['ok'])->toBeFalse();
    expect($result['failures'][0]['message'])->toContain('expected a pause');
});

it('counts passes and collects every failure across cases', function () {
    $file = [
        'kind' => 'switch_case',
        'cases' => [
            ['name' => 'ok', 'ports' => ['a'], 'expect' => ['ports' => ['a']]],
            ['name' => 'bad', 'ports' => ['a'], 'expect' => ['ports' => ['zzz']]],
        ],
    ];

    $result = (new FixtureRunner())->run($file, static fn (ExecutionContext $ctx) => Port::only('a'));

    expect($result['passed'])->toBe(1);
    expect($result['failures'])->toHaveCount(1);
    expect($result['failures'][0]['case'])->toBe('bad');
});
