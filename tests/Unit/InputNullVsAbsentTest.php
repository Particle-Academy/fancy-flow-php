<?php

declare(strict_types=1);

use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;

/**
 * `input()` must tell a port bound to NULL from a port that is not bound.
 *
 * It read `$this->inputs[$port] ?? $default`, so a port holding an explicit
 * null returned the DEFAULT. Eleven executors call
 * `$ctx->input('in', $ctx->inputs)`, whose default is the whole inputs map — so
 * a null `in` did not yield null, it yielded **every input the node had**.
 *
 * That is worse than the wrapper leak it sits underneath, and for a specific
 * reason: the wrapper was visibly odd (`branch` / `value` keys nothing
 * declares). The inputs map is **plausible** — it looks exactly like real data,
 * so a downstream node reads fields that came from the wrong place and nothing
 * looks wrong anywhere.
 *
 * The fallback itself is right and stays: a trigger has no `in` edge, and
 * "the `in` port, or everything if there is no `in` port" is what lets an entry
 * node read its seeded payload. Only the ABSENT case may fall back.
 *
 * This is the third layer of one collapse — `input()`, `activatedPorts`, and
 * the regression test written for `activatedPorts`, which asserted with
 * `?? '__absent__'` and so could not see the null it was testing for. One
 * language idiom reached for reflexively: `??` is safe only where null is not a
 * legal value.
 *
 * Found by the reference consumer asking whether the CAUSE had been fixed or
 * only the symptom.
 */
function ctxWith(array $inputs): ExecutionContext
{
    return new ExecutionContext(
        node: new FlowNode(id: 'n', type: 'k'),
        inputs: $inputs,
        emit: static function (): void {},
    );
}

it('returns NULL for a port explicitly bound to null', function () {
    $ctx = ctxWith(['in' => null, 'other' => 'x']);

    // NOT the default. The port is bound; its value is null.
    expect($ctx->input('in', $ctx->inputs))->toBeNull();
});

it('still falls back when the port is ABSENT', function () {
    // The behaviour the default exists for: a trigger has no `in` edge, so it
    // reads its seeded payload. Removing this would break every entry node.
    $ctx = ctxWith(['topic' => 'ports']);

    expect($ctx->input('in', $ctx->inputs))->toBe(['topic' => 'ports']);
});

it('returns a real value unchanged', function () {
    $ctx = ctxWith(['in' => ['user' => 'ada']]);

    expect($ctx->input('in', $ctx->inputs))->toBe(['user' => 'ada']);
});

it('a null port with no default is still null, not a surprise', function () {
    expect(ctxWith(['in' => null])->input('in'))->toBeNull();
    expect(ctxWith([])->input('in'))->toBeNull();
});

it('false and 0 survive too — they were never the point, but ?? eats them as well', function () {
    // `??` only collapses null, so these were already fine. Pinned because the
    // fix changes the operator, and a fix that quietly altered falsy handling
    // would be a worse bug than the one being fixed.
    expect(ctxWith(['in' => false])->input('in', 'fallback'))->toBeFalse();
    expect(ctxWith(['in' => 0])->input('in', 'fallback'))->toBe(0);
    expect(ctxWith(['in' => ''])->input('in', 'fallback'))->toBe('');
});
