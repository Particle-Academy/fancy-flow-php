<?php

declare(strict_types=1);

use FancyFlow\Laravel\Runs\NodeRetryPolicy;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Schema\FlowNode;

/*
 * `sideEffects` must be DECLARED by something, or the retry protection built on
 * it is unreachable.
 *
 * The mechanism landed in 0.10 and `NodeRetryPolicy` reads it:
 *
 *     return $kinds->get($node->type)?->sideEffects === self::UNSAFE;
 *
 * ZERO of the 26 builtin kinds declared it. So that expression returned false
 * for every node in existence, `tries` always fell through to the configured
 * default, and the one-attempt pin could never engage.
 *
 * The docs promise otherwise, TWICE. `NodeRetryPolicy`'s own docblock says
 * *"A node declaring sideEffects: unsafe-to-replay is pinned to ONE attempt"*
 * and offers `git_pr_open` opening a second pull request as the motivating
 * example; `AGENTS.md` says *"unsafe-to-replay, so per_node pins it to one
 * attempt (never double-files)"*. A consumer reading either would reasonably
 * rely on it.
 *
 * The reading half shipped and the declaring half never did -- the same shape as
 * `graph.inputs` being dropped on import: two of three links present, chain
 * silently dead. Reported by a consumer who MEASURED it rather than reading it.
 */

function seRegistry(): NodeKindRegistry
{
    return Builtin::register(new NodeKindRegistry(), withStructural: true);
}

it('has at least one kind that is actually pinned to one attempt', function () {
    // The liveness check, and the one that would have caught this. Everything
    // below can pass over an empty set; this cannot.
    $kinds = seRegistry();
    $config = ['queue' => ['tries' => 3, 'backoff' => 5]];

    $node = new FlowNode(id: 'w', type: 'webhook_out');

    expect(NodeRetryPolicy::isUnsafeToReplay($node, $kinds))->toBeTrue(
        'webhook_out POSTs to somebody else`s system; a second attempt is a second delivery',
    );
    expect(NodeRetryPolicy::tries($node, $kinds, $config))->toBe(1);
    expect(NodeRetryPolicy::backoff($node, $kinds, $config))->toBe(0);
});

it('still lets an ordinary node take the configured retries', function () {
    // The other half. Without it, "everything is unsafe" would also satisfy the
    // test above, and that is a different wrong answer -- it would turn every
    // transient failure into a permanent one.
    $kinds = seRegistry();
    $config = ['queue' => ['tries' => 3, 'backoff' => 5]];

    $node = new FlowNode(id: 't', type: 'transform');

    expect(NodeRetryPolicy::isUnsafeToReplay($node, $kinds))->toBeFalse();
    expect(NodeRetryPolicy::tries($node, $kinds, $config))->toBe(3);
});

it('only ever uses the three vocabulary values', function () {
    // A typo is as dead as a null: `sideEffects => 'unsafe'` would read as
    // declared to a human and be invisible to the policy, which compares against
    // the exact string. This is the check that makes the declaration real
    // rather than decorative.
    $allowed = ['none', 'idempotent', NodeRetryPolicy::UNSAFE];

    foreach (seRegistry()->all() as $kind) {
        if ($kind->sideEffects === null) {
            continue;
        }

        expect(in_array($kind->sideEffects, $allowed, true))->toBeTrue(
            "{$kind->name} declares sideEffects `{$kind->sideEffects}`, which is not one of: ".implode(', ', $allowed),
        );
    }
});

it('leaves exactly the kinds whose safety is NOT a property of the kind undeclared', function () {
    // Undeclared is a real answer for these, and the list is pinned so that
    // adding a kind and FORGETTING the field shows up here rather than silently
    // joining the set that no retry rule can see.
    //
    // Each of these is genuinely unanswerable at the kind level:
    //
    //  - api_request   the safety of a retry is the HTTP METHOD, which is
    //                  config. A GET is safe and a POST is not, and AGENTS.md
    //                  cites "a flaky HTTP node can have several" attempts as
    //                  the retryable example -- so declaring the kind unsafe
    //                  would make every read-only call fail permanently.
    //  - llm_call      a retry costs money and returns a DIFFERENT answer, but
    //  - llm_router    writes nothing external. Neither `idempotent` (the result
    //                  differs) nor `unsafe-to-replay` (nothing is duplicated)
    //                  is true.
    //  - tool_use      its effects are the HOST's tool, entirely unknown here.
    //  - subflow       its effects are the child graph's, which is whatever the
    //                  child's nodes declare.
    //  - user_input    these PAUSE rather than fail; retry is not the axis they
    //  - human_approval live on.
    //
    // A host that knows its own usage pins any of them with the per-kind
    // `queue.node_tries` override.
    $undeclared = [];
    foreach (seRegistry()->all() as $kind) {
        if ($kind->sideEffects === null) {
            $undeclared[] = preg_replace('#^@particle-academy/#', '', $kind->name);
        }
    }
    sort($undeclared);

    expect($undeclared)->toBe([
        'api_request',
        'human_approval',
        'llm_call',
        'llm_router',
        'subflow',
        'tool_use',
        'user_input',
    ]);
});
