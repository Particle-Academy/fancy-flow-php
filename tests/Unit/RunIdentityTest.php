<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunIdentity;
use FancyFlow\Runtime\RunOptions;

it('keys a top-level node on the run and its own id', function () {
    expect((new RunIdentity('run_a'))->stepKey('pay'))->toBe('run_a:pay');
});

it('keeps the SAME key across attempts of the same step', function () {
    // The whole point. A key that moves with the attempt creates a second
    // charge on the first timeout, which is the failure it exists to prevent.
    $first = new RunIdentity('run_a', [], 1);
    $retry = new RunIdentity('run_a', [], 5);

    expect($retry->stepKey('pay'))->toBe($first->stepKey('pay'));
});

it('gives a different key to a different occurrence of the same node', function () {
    $id = new RunIdentity('run_a');

    expect($id->stepKey('pay', 0))->not->toBe($id->stepKey('pay', 1))
        ->and($id->stepKey('pay', 0))->not->toBe($id->stepKey('pay'));
});

it('gives a different key to the same node in a different run', function () {
    expect((new RunIdentity('run_a'))->stepKey('pay'))
        ->not->toBe((new RunIdentity('run_b'))->stepKey('pay'));
});

it('descends without colliding with a same-named node in the parent', function () {
    $parent = new RunIdentity('run_a');
    $child = $parent->descend('billing');

    expect($child->stepKey('pay'))->toBe('run_a:billing/pay')
        ->and($child->stepKey('pay'))->not->toBe($parent->stepKey('pay'));
});

it('carries attempt and the first-attempt clock down into a subflow', function () {
    $child = (new RunIdentity('run_a', [], 3, '2026-08-19T00:00:00Z'))->descend('billing');

    expect($child->attempt)->toBe(3)
        ->and($child->firstAttemptAt)->toBe('2026-08-19T00:00:00Z');
});

it('does not let a slash in a node id impersonate a nesting level', function () {
    $flat = (new RunIdentity('run_a'))->stepKey('a/b');
    $nested = (new RunIdentity('run_a'))->descend('a')->stepKey('b');

    expect($flat)->toBe('run_a:a%2Fb')
        ->and($nested)->toBe('run_a:a/b')
        ->and($flat)->not->toBe($nested);
});

it('escapes the escape character first', function () {
    expect((new RunIdentity('run_a'))->stepKey('a%2Fb'))->toBe('run_a:a%252Fb')
        ->and((new RunIdentity('run_a'))->stepKey('a%2Fb'))
        ->not->toBe((new RunIdentity('run_a'))->stepKey('a/b'));
});

it('is immutable - descend returns a new identity', function () {
    $parent = new RunIdentity('run_a');
    $parent->descend('billing');

    expect($parent->path)->toBe([]);
});

it('round-trips through a queue payload', function () {
    $id = new RunIdentity('run_a', ['billing'], 4, '2026-08-19T00:00:00Z');
    $rebuilt = RunIdentity::from(json_decode(json_encode($id), true));

    expect($rebuilt->stepKey('pay'))->toBe($id->stepKey('pay'))
        ->and($rebuilt->attempt)->toBe(4);
});

it('refuses an empty run key rather than minting a useless identity', function () {
    new RunIdentity('  ');
})->throws(InvalidArgumentException::class);

it('is replay-safe on attempt 1 however long the run was parked', function () {
    $parked = new RunIdentity('run_a', [], 1, '2026-08-01T00:00:00Z');

    expect($parked->isReplaySafe(86400, '2026-08-19T00:00:00Z'))->toBeTrue();
});

it('is replay-safe for a retry inside the window, inclusive of its edge', function () {
    $id = new RunIdentity('run_a', [], 2, '2026-08-18T00:00:00Z');

    expect($id->isReplaySafe(86400, '2026-08-19T00:00:00Z'))->toBeTrue();
});

it('is NOT replay-safe one second past the window', function () {
    $id = new RunIdentity('run_a', [], 2, '2026-08-18T00:00:00Z');

    expect($id->isReplaySafe(86400, '2026-08-19T00:00:01Z'))->toBeFalse();
});

it('treats a zero window as a window, not as an absent one', function () {
    $retry = new RunIdentity('run_a', [], 2, '2026-08-19T00:00:00Z');
    $first = new RunIdentity('run_a', [], 1, '2026-08-19T00:00:00Z');

    expect($retry->isReplaySafe(0, '2026-08-19T00:00:00Z'))->toBeFalse()
        ->and($first->isReplaySafe(0, '2026-08-19T00:00:00Z'))->toBeTrue();
});

it('treats a null window as a provider that never forgets', function () {
    $id = new RunIdentity('run_a', [], 9, '2020-01-01T00:00:00Z');

    expect($id->isReplaySafe(null, '2026-08-19T00:00:00Z'))->toBeTrue();
});

it('clamps clock skew to zero rather than refusing a legitimate retry', function () {
    $id = new RunIdentity('run_a', [], 2, '2026-08-19T00:00:10Z');

    expect($id->isReplaySafe(86400, '2026-08-19T00:00:00Z'))->toBeTrue();
});

it('refuses to guess at an unparseable timestamp', function () {
    (new RunIdentity('run_a', [], 2, 'not a date'))->isReplaySafe(86400, '2026-08-19T00:00:00Z');
})->throws(InvalidArgumentException::class);

it('reaches the executor through the run options', function () {
    $seen = [];
    $executors = (new ExecutorRegistry())->bind('*', function (ExecutionContext $ctx) use (&$seen) {
        $seen[] = $ctx->run?->stepKey($ctx->node->id);

        return ['ok' => true];
    });

    (new FlowRunner())->run(
        ffGraph([ffNode('a'), ffNode('b')], [ffEdge('e1', 'a', 'b')]),
        $executors,
        null,
        new RunOptions(run: new RunIdentity('run_a')),
    );

    expect($seen)->toBe(['run_a:a', 'run_a:b']);
});

it('accepts a bare run key so a host does not have to build the object', function () {
    $key = null;
    $executors = (new ExecutorRegistry())->bind('*', function (ExecutionContext $ctx) use (&$key) {
        $key = $ctx->run?->stepKey('a');

        return [];
    });

    (new FlowRunner())->run(ffGraph([ffNode('a')]), $executors, null, new RunOptions(run: 'run_a'));

    expect($key)->toBe('run_a:a');
});

it('leaves the identity null when the host supplied none', function () {
    // Deliberately NOT auto-minted. A random key per call changes on every
    // whole-run retry, which is the failure this exists to stop.
    $run = 'unset';
    $executors = (new ExecutorRegistry())->bind('*', function (ExecutionContext $ctx) use (&$run) {
        $run = $ctx->run;

        return [];
    });

    (new FlowRunner())->run(ffGraph([ffNode('a')]), $executors);

    expect($run)->toBeNull();
});
