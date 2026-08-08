<?php

use FancyFlow\Laravel\LiveContract;

/**
 * The PHP half of the flow Live Contract parity check.
 *
 * Both sides assert the same thing, because a mirror pair drifts when only ONE
 * side is edited and neither can tell alone: rename an event here and nothing
 * throws — the browser listens for a name nobody broadcasts, nothing
 * invalidates the cache, and the run list quietly stops updating.
 */
it('declares every event under its own namespace', function () {
    foreach (LiveContract::eventNames() as $event) {
        expect($event)->toStartWith(LiveContract::NAMESPACE.'.');
    }
});

it('covers a run and NOT per-node chatter', function () {
    // NodeStatusChanged / NodeOutput fire per node, many times a second on a
    // wide graph. A log line is a stream, not a cache entry — in the contract, a
    // 40-node run would invalidate the run list forty times while it executed.
    $names = implode(' ', LiveContract::eventNames());

    expect($names)->not->toContain('node');
    expect($names)->not->toContain('output');
});

it('gives a run parking on a human its own event', function () {
    expect(LiveContract::eventNames())->toContain('flow.run.awaiting');
});

it('invalidates at least one key per event, inside its namespace', function () {
    foreach (LiveContract::EVENTS as $event => $keys) {
        expect($keys)->not->toBeEmpty("\"$event\" invalidates nothing");

        foreach ($keys as $key) {
            expect($key[0])->toBe(LiveContract::NAMESPACE, "\"$event\" invalidates outside its namespace");
        }
    }
});

it('points every mapped source at a real event class', function () {
    // SOURCES tells a host wiring broadcasting which in-process event to listen
    // for. A class-string naming something that does not exist is a runtime
    // failure in someone else's app, discovered when they try to use it.
    foreach (LiveContract::SOURCES as $event => $class) {
        expect(class_exists($class))->toBeTrue("$event maps to a missing class: $class");
        expect(LiveContract::eventNames())->toContain($event);
    }
});

it('agrees with the TypeScript contract, event for event', function () {
    $path = __DIR__.'/../../../fancy-flow/src/live.ts';

    if (! is_file($path)) {
        expect(true)->toBeTrue();  // sibling absent in a standalone clone

        return;
    }

    preg_match_all('/\{\s*\n?\s*event:\s*"([^"]+)"/', (string) file_get_contents($path), $m);
    $jsEvents = $m[1] ?? [];

    // Guard the guard: a regex matching nothing makes the comparison vacuous.
    expect($jsEvents)->not->toBeEmpty('parsed no events out of live.ts — the regex missed');

    sort($jsEvents);
    $php = LiveContract::eventNames();
    sort($php);

    expect($jsEvents)->toBe($php);
});
