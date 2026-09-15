<?php

declare(strict_types=1);

use FancyFlow\Laravel\Runs\NodeClaims;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/**
 * `NodeClaims::skip()` says whether THIS call settled the node.
 *
 * The undelivered-edge warning for a skipped target is announced when the skip
 * is recorded, and two advances can compute the same skip at once. Keying the
 * announcement on the skip having happened HERE is what makes it once per run
 * rather than once per advance that noticed.
 */
it('settles a node once, and reports only the call that did', function (): void {
    expect(NodeClaims::skip('run_skip_once', 'x'))->toBeTrue();
    expect(NodeClaims::skip('run_skip_once', 'x'))->toBeFalse();
});

it('never re-settles a node that genuinely ran', function (): void {
    NodeClaims::claim('run_skip_ran', 'y', 'owner-1');
    NodeClaims::complete('run_skip_ran', 'y', 'output', ['out']);

    // A computed skip must not overwrite a node that completed -- and must not
    // report that it did, or a warning would be announced about a node that ran.
    expect(NodeClaims::skip('run_skip_ran', 'y'))->toBeFalse();
});
