<?php

declare(strict_types=1);

use FancyFlow\Runtime\Pause;
use FancyFlow\Runtime\PauseSignal;

it('round-trips a signal', function () {
    $signal = new PauseSignal('n1', 'input', ['fields' => ['email']]);
    $decoded = Pause::decode(Pause::encode($signal));

    expect($decoded)->not->toBeNull();
    expect($decoded->nodeId)->toBe('n1');
    expect($decoded->awaiting)->toBe('input');
    expect($decoded->detail)->toBe(['fields' => ['email']]);
});

it('omits a null detail', function () {
    expect(Pause::encode(new PauseSignal('n1', 'approval')))
        ->toBe(Pause::PREFIX.'{"nodeId":"n1","awaiting":"approval"}');
});

it('survives a node id containing a colon', function () {
    // Why the payload is JSON and not delimited fields: a positional encoding
    // that breaks on user data only ever breaks in someone else's graph.
    $decoded = Pause::decode(Pause::encode(new PauseSignal('group:1:step:2', 'input')));

    expect($decoded->nodeId)->toBe('group:1:step:2');
});

it('carries an author-defined awaiting value through untouched', function () {
    $decoded = Pause::decode(Pause::encode(new PauseSignal('n1', 'signature', ['docId' => 7])));

    expect($decoded->awaiting)->toBe('signature');
    expect($decoded->detail)->toBe(['docId' => 7]);
    expect($decoded->isApproval())->toBeFalse();
    expect($decoded->isInput())->toBeFalse();
});

it('returns null for a real failure', function (?string $reason) {
    expect(Pause::decode($reason))->toBeNull();
    expect(Pause::is($reason))->toBeFalse();
})->with([
    'a genuine error' => 'Request failed with status 500',
    'an empty string' => '',
    'null' => null,
    'the prefix with a malformed body' => Pause::PREFIX.'{not json',
    'a payload missing nodeId' => Pause::PREFIX.'{"awaiting":"input"}',
    'a payload missing awaiting' => Pause::PREFIX.'{"nodeId":"n1"}',
    'a payload with a non-string nodeId' => Pause::PREFIX.'{"nodeId":7,"awaiting":"input"}',
]);

it('still decodes the pre-contract prefixes', function (string $reason, string $nodeId, string $awaiting) {
    // These are sitting in the error column of every run that parked under an
    // older version. A resume path that only works for new runs strands them.
    $decoded = Pause::decode($reason);

    expect($decoded->nodeId)->toBe($nodeId);
    expect($decoded->awaiting)->toBe($awaiting);
})->with([
    ['awaiting-approval:node-7', 'node-7', 'approval'],
    ['awaiting-input:node-7', 'node-7', 'input'],
    ['awaiting-input:a:b', 'a:b', 'input'],
]);

it('matches the TypeScript wire format byte for byte', function (string $fromTs, string $nodeId, string $awaiting) {
    // The parity claim, pinned. These strings were produced by
    // @particle-academy/fancy-flow's encodePause(). A consumer authoring in TS
    // and executing in PHP depends on both runtimes reading the same bytes, so
    // this must fail loudly if either side's encoding drifts.
    $decoded = Pause::decode($fromTs);

    expect($decoded)->not->toBeNull();
    expect($decoded->nodeId)->toBe($nodeId);
    expect($decoded->awaiting)->toBe($awaiting);
})->with([
    ['fancy-flow:pause:{"nodeId":"n1","awaiting":"approval"}', 'n1', 'approval'],
    ['fancy-flow:pause:{"nodeId":"h","awaiting":"input","detail":{"fields":["email"]}}', 'h', 'input'],
    ['fancy-flow:pause:{"nodeId":"group:1","awaiting":"signature","detail":{"docId":7}}', 'group:1', 'signature'],
]);

it('encodes what TypeScript would encode', function () {
    // The other direction: a PHP-emitted pause must be readable by a TS runner.
    expect(Pause::encode(new PauseSignal('h', 'input', ['fields' => ['email']])))
        ->toBe('fancy-flow:pause:{"nodeId":"h","awaiting":"input","detail":{"fields":["email"]}}');
});
