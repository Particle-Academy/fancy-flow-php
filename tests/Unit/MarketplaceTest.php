<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\LlmRoute;
use FancyFlow\Capabilities\LlmRouteRequest;
use FancyFlow\Marketplace\FixtureRunner;
use FancyFlow\Marketplace\NodeManifest;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Port;
use FancyFlow\Runtime\RunEvent;

function validManifest(array $overrides = []): array
{
    return array_merge([
        'schemaVersion' => NodeManifest::SCHEMA_VERSION,
        'name' => '@acme/fancy-flow-salesforce',
        'kind' => '@acme/salesforce_upsert',
        'runtimes' => [
            'ts' => ['entry' => 'dist/executor.js', 'engine' => '^0.15'],
            'php' => ['package' => 'acme/fancy-flow-salesforce:^0.1', 'engine' => '^0.7'],
        ],
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

    expect(fields($problems))->toContain('name', 'kind', 'runtimes', 'fixtures');
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
        ['kind' => '@acme/x', 'runtimes' => ['ts' => ['entry' => 'dist/x.js', 'engine' => '^0.15']]],
        ['php'],
    );

    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('error');
    expect($problems[0]['message'])->toContain('executes on php');
});

it('passes when the node implements every runtime the host executes on', function () {
    expect(NodeManifest::checkRuntimeSupport(validManifest(), ['ts', 'php'], ['ts' => '0.15.1', 'php' => '0.7.0']))->toBe([]);
});

it('catches a host whose OTHER runtime is too old', function () {
    // The failure a single fancyFlow range could not express.
    $problems = NodeManifest::checkRuntimeSupport(validManifest(), ['ts', 'php'], ['ts' => '0.15.1', 'php' => '0.5.0']);

    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('error');
    expect($problems[0]['field'])->toBe('runtimes.php.engine');
});

it('warns rather than passing silently when the host reports no version', function () {
    // "We did not check" and "it is fine" must not look the same.
    $problems = NodeManifest::checkRuntimeSupport(validManifest(), ['ts'], []);

    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('warning');
});

it('rejects a leftover single fancyFlow range', function () {
    $problems = NodeManifest::validate(validManifest(['fancyFlow' => '>=0.10.1']));

    expect(fields($problems))->toContain('fancyFlow');
});

it('requires an engine range on every runtime', function () {
    $problems = NodeManifest::validate(validManifest(['runtimes' => ['ts' => ['entry' => 'dist/x.js']]]));

    expect(fields($problems))->toContain('runtimes.ts.engine');
});

it('matches the TypeScript satisfiesRange, clause for clause', function (string $version, string $range, bool $expected) {
    // Pinned against the TS implementation: a package accepted by one
    // runtime's tooling and rejected by the other is worse than no check.
    expect(NodeManifest::satisfiesRange($version, $range))->toBe($expected);
})->with([
    ['0.15.1', '^0.15', true],
    ['0.16.0', '^0.15', false],
    ['1.2.0', '^1.0', true],
    ['2.0.0', '^1.0', false],
    ['0.7.0', '>=0.7', true],
    ['0.5.0', '>=0.7', false],
    ['0.7.3', '~0.7.1', true],
    ['0.8.0', '~0.7.1', false],
    ['9.9.9', '*', true],
    ['0.7.0', '^0.5 || ^0.7', true],
    ['1.0.0', 'not-a-range', false],
]);

it('accepts aliases, configVersion and sideEffects', function () {
    expect(NodeManifest::isValid(validManifest([
        'aliases' => ['@acme/old_name'],
        'configVersion' => 2,
        'sideEffects' => 'unsafe-to-replay',
    ])))->toBeTrue();
});

it('rejects an unknown sideEffects value', function () {
    expect(NodeManifest::isValid(validManifest(['sideEffects' => 'sometimes'])))->toBeFalse();
});

it('errors on a missing REQUIRED capability', function () {
    // Surfaced at author time so an editor can grey the node, instead of it
    // installing cleanly and silently no-opping at run time.
    $problems = NodeManifest::checkCapabilities(
        ['kind' => '@acme/x', 'capabilities' => ['llm' => 'required']],
        ['llm' => false],
    );

    expect($problems)->toHaveCount(1);
    expect($problems[0]['level'])->toBe('error');
});

it('only warns on a missing OPTIONAL capability', function () {
    $problems = NodeManifest::checkCapabilities(
        ['kind' => '@acme/x', 'capabilities' => ['doc' => 'optional']],
        ['doc' => false],
    );

    expect($problems[0]['level'])->toBe('warning');
});

it('rejects a bare capability list, which cannot express the level', function () {
    expect(NodeManifest::isValid(validManifest(['capabilities' => ['llm']])))->toBeFalse();
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

    expect(implode(' | ', $problems))->toContain('must assert at least one');
});

it('requires at least one failure or pause case', function () {
    // "Does it fail the same way" deserves equal weight to "does it succeed the
    // same way" — the incident behind this whole mechanism was a FAILURE that
    // reported completed with no error.
    $allHappy = ['kind' => '@acme/x', 'cases' => [['name' => 'happy', 'expect' => ['ports' => ['out']]]]];

    expect(FixtureRunner::validateFile($allHappy))->toEqual([
        'At least one case must assert a failure (`expect.error`) or a pause (`expect.pause`). '
        .'Every case here covers a success path, and the divergence this format exists to catch reported '
        .'success while doing nothing.',
    ]);
});

it('accepts a failure case as the required coverage', function (string $key) {
    $expectation = $key === 'error' ? ['error' => 'boom'] : ['pause' => ['awaiting' => 'input']];
    $file = ['kind' => '@acme/x', 'cases' => [['name' => 'sad', 'expect' => $expectation]]];

    expect(FixtureRunner::validateFile($file))->toBe([]);
})->with(['error', 'pause']);

it('catches a fixture file for a different kind than the manifest declares', function () {
    $problems = FixtureRunner::validateFile(
        ['kind' => '@acme/y', 'cases' => [['name' => 'n', 'expect' => ['ports' => ['out']]]]],
        '@acme/x',
    );

    expect($problems[0])->toContain('manifest declares');
});

it('accepts a well-formed file', function () {
    $file = [
        'kind' => '@acme/x',
        'cases' => [
            ['name' => 'happy', 'expect' => ['ports' => ['out']]],
            ['name' => 'sad', 'expect' => ['error' => 'boom']],
        ],
    ];

    expect(FixtureRunner::validateFile($file, '@acme/x'))->toBe([]);
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

// ── Stubs, resume, events, legacy ids ──────────────────────────────────────

it('resumes a paused case with a submission and asserts the final state', function () {
    // Pause/resume is the only path crossing a persistence boundary, so it is
    // where two runtimes are most likely to drift — and it had no coverage.
    $waiter = static function (ExecutionContext $ctx) {
        $values = $ctx->inputs['values'] ?? null;
        if ($values === null) {
            $ctx->pauseForHuman('signature', ['doc' => 'nda.pdf']);
        }

        return $values;
    };

    $result = (new FixtureRunner())->run([
        'kind' => 'user_input',
        'cases' => [[
            'name' => 'waits then finishes',
            'expect' => [
                'pause' => ['awaiting' => 'signature', 'detail' => ['doc' => 'nda.pdf']],
                'afterResume' => ['submit' => ['signedBy' => 'ada'], 'ports' => ['out'], 'value' => ['signedBy' => 'ada']],
            ],
        ]],
    ], $waiter);

    expect($result['failures'])->toBe([]);
    expect($result['ok'])->toBeTrue();
});

it('fails when the resumed run does not reach the expected state', function () {
    $waiter = static function (ExecutionContext $ctx) {
        $values = $ctx->inputs['values'] ?? null;
        if ($values === null) {
            $ctx->pauseForHuman('signature');
        }

        return $values;
    };

    $result = (new FixtureRunner())->run([
        'kind' => 'user_input',
        'cases' => [[
            'name' => 'wrong resume value',
            'expect' => [
                'pause' => ['awaiting' => 'signature'],
                'afterResume' => ['submit' => ['signedBy' => 'ada'], 'value' => ['signedBy' => 'grace']],
            ],
        ]],
    ], $waiter);

    expect($result['ok'])->toBeFalse();
    expect($result['failures'][0]['message'])->toContain('after resume');
});

it('builds an llm_client stub from fixture data, so CI needs no provider', function () {
    // If the stub format is not shared, each runtime stubs differently and the
    // fixtures stop comparing like with like — parity theatre.
    $usesLlm = static function (ExecutionContext $ctx) {
        $choice = Capabilities::llmClient()->chooseRoute(new LlmRouteRequest(
            prompt: '?',
            routes: [new LlmRoute('billing'), new LlmRoute('support')],
        ));

        return Port::only($choice->port, ['reason' => $choice->reason]);
    };

    $result = (new FixtureRunner())->run([
        'kind' => 'llm_router',
        'cases' => [[
            'name' => 'routes to billing',
            'ports' => ['billing', 'support'],
            'stubs' => ['llm_client' => ['chooseRoute' => ['port' => 'billing', 'reason' => 'invoice question']]],
            'expect' => ['ports' => ['billing'], 'value' => ['reason' => 'invoice question']],
        ]],
    ], $usesLlm);

    expect($result['failures'])->toBe([]);
    expect($result['ok'])->toBeTrue();
});

it('asserts emitted events, so a warning contract cannot degrade silently', function () {
    $warns = static function (ExecutionContext $ctx) {
        $ctx->emit(RunEvent::log('warn', 'model chose an unknown port; using fallback', $ctx->node->id));

        return ['ok' => true];
    };

    $file = [
        'kind' => 'transform',
        'cases' => [[
            'name' => 'warns on fallback',
            'ports' => ['out'],
            'expect' => [
                'ports' => ['out'],
                'events' => [['type' => 'log', 'level' => 'warn', 'messageContains' => 'unknown port']],
            ],
        ]],
    ];

    expect((new FixtureRunner())->run($file, $warns)['ok'])->toBeTrue();

    // And it fails when the event is absent — otherwise the assertion is decor.
    $silent = static fn (ExecutionContext $ctx) => ['ok' => true];
    $failed = (new FixtureRunner())->run($file, $silent);

    expect($failed['ok'])->toBeFalse();
    expect($failed['failures'][0]['message'])->toContain('expected an emitted event');
});
