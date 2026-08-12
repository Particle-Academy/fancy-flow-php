<?php

declare(strict_types=1);

use FancyFlow\Schema\ImportIssue;
use FancyFlow\Security\GraphPolicy;
use FancyFlow\Security\UnsafeGraph;

function gp_graph(array $nodes, array $edges = []): array
{
    return ['graph' => ['nodes' => $nodes, 'edges' => $edges]];
}

function gp_node(string $id, string $kind, array $config = []): array
{
    return ['id' => $id, 'kind' => $kind, 'config' => $config];
}

/**
 * The trust layer: what an untrusted author's graph must satisfy before it is
 * allowed near a queue.
 *
 * `Workflow::import()` asks whether a graph is COHERENT. This asks whether it
 * is safe to ACCEPT — a graph arriving over HTTP is a payload first and a
 * workflow second, and it gets persisted and later rehydrated by a worker that
 * trusts it.
 */
describe('kind policy', function () {
    it('permits what the allowlist names', function () {
        $policy = GraphPolicy::untrusted()->allowKinds(['manual_trigger', 'transform']);

        expect($policy->inspect(gp_graph([
            gp_node('a', 'manual_trigger'),
            gp_node('b', 'transform'),
        ])))->toBe([]);
    });

    it('refuses a kind that is not on the allowlist', function () {
        $policy = GraphPolicy::untrusted()->allowKinds(['manual_trigger']);

        $issues = $policy->inspect(gp_graph([gp_node('a', 'api_request')]));

        expect($issues)->toHaveCount(1);
        expect($issues[0]->nodeId)->toBe('a');
    });

    it('CANNOT be bypassed by spelling the kind differently', function () {
        // The attack this exists to stop, and the same defect class as the
        // human gate in #4 — except there it failed silently, and here it would
        // be a bypass. Every id a kind answers to is resolved before any
        // comparison, so the canonical spelling is refused exactly as the bare
        // one is.
        $policy = GraphPolicy::untrusted()->allowKinds(['manual_trigger']);

        foreach (['api_request', '@particle-academy/api_request', '@fancy/api_request'] as $spelling) {
            expect($policy->inspect(gp_graph([gp_node('a', $spelling)])))
                ->not->toBe([], $spelling.' slipped through the allowlist');
        }
    });

    it('CANNOT be bypassed by respelling a DENIED kind — the case that actually matters', function () {
        // An allowlist fails closed, so an unknown spelling is refused whether
        // or not the policy understands aliases. A DENYLIST does not: deny
        // `api_request` and a literal comparison happily accepts
        // `@particle-academy/api_request`, which is the same executor.
        //
        // This is the test that would catch alias-awareness regressing. The
        // allowlist version above cannot, and I nearly shipped it believing it
        // could.
        $policy = GraphPolicy::untrusted()->denyKinds(['api_request']);

        foreach (['api_request', '@particle-academy/api_request', '@fancy/api_request'] as $spelling) {
            expect($policy->inspect(gp_graph([gp_node('a', $spelling)])))
                ->not->toBe([], $spelling.' bypassed the denylist');
        }
    });

    it('allows every spelling of an ALLOWED kind, so the policy is not a trap either', function () {
        // The other direction matters just as much: an allowlist that accepts
        // only one spelling rejects graphs an editor legitimately saved.
        $policy = GraphPolicy::untrusted()->allowKinds(['user_input']);

        foreach (['user_input', '@particle-academy/user_input', '@fancy/user_input'] as $spelling) {
            expect($policy->inspect(gp_graph([gp_node('a', $spelling)])))
                ->toBe([], $spelling.' was refused despite being allowed');
        }
    });

    it('refuses a denied kind even when the allowlist names it', function () {
        // A contradiction resolves the safe way.
        $policy = GraphPolicy::untrusted()->allowKinds(['api_request'])->denyKinds(['api_request']);

        expect($policy->inspect(gp_graph([gp_node('a', 'api_request')])))->not->toBe([]);
    });

    it('has no allowlist by default, so the caller must opt in deliberately', function () {
        expect(GraphPolicy::untrusted()->inspect(gp_graph([gp_node('a', 'api_request')])))->toBe([]);
    });
});

describe('byte hygiene', function () {
    it('refuses a NUL byte in config', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph([gp_node('a', 'transform', ['x' => "ok\0hidden"])]));

        expect($issues)->not->toBe([]);
    });

    it('refuses control characters', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph([gp_node('a', 'transform', ['x' => "a\x1bb"])]));

        expect($issues)->not->toBe([]);
    });

    it('allows tab, newline and carriage return, which real prompts contain', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph([
            gp_node('a', 'transform', ['prompt' => "Line one\nLine two\r\n\tIndented"]),
        ]));

        expect($issues)->toBe([]);
    });

    it('checks keys as well as values', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph([gp_node('a', 'transform', ["bad\0key" => 'x'])]));

        expect($issues)->not->toBe([]);
    });
});

describe('size caps', function () {
    it('refuses too many nodes', function () {
        $nodes = [];
        for ($i = 0; $i < 61; $i++) {
            $nodes[] = gp_node("n{$i}", 'transform');
        }

        expect(GraphPolicy::untrusted()->inspect(gp_graph($nodes)))->not->toBe([]);
    });

    it('refuses a nesting bomb', function () {
        $deep = 'leaf';
        for ($i = 0; $i < 40; $i++) {
            $deep = ['n' => $deep];
        }

        expect(GraphPolicy::untrusted()->inspect(gp_graph([gp_node('a', 'transform', ['x' => $deep])])))->not->toBe([]);
    });

    it('refuses an oversized string', function () {
        $issues = GraphPolicy::untrusted()
            ->withLimits(maxStringLength: 10)
            ->inspect(gp_graph([gp_node('a', 'transform', ['x' => str_repeat('a', 11)])]));

        expect($issues)->not->toBe([]);
    });

    it('refuses an oversized payload before walking it', function () {
        $issues = GraphPolicy::untrusted()
            ->withLimits(maxBytes: 100)
            ->inspect(gp_graph([gp_node('a', 'transform', ['x' => str_repeat('a', 500)])]));

        expect($issues)->toHaveCount(1);
    });
});

describe('structure', function () {
    it('refuses duplicate node ids', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph([
            gp_node('a', 'transform'),
            gp_node('a', 'transform'),
        ]));

        expect($issues)->not->toBe([]);
    });

    it('refuses an edge pointing at a node that does not exist', function () {
        $issues = GraphPolicy::untrusted()->inspect(gp_graph(
            [gp_node('a', 'transform')],
            [['id' => 'e1', 'source' => 'a', 'target' => 'ghost']],
        ));

        expect($issues)->not->toBe([]);
    });

    it('refuses a node with no kind', function () {
        expect(GraphPolicy::untrusted()->inspect(gp_graph([['id' => 'a']])))->not->toBe([]);
    });
});

describe('extension and enforcement', function () {
    it('runs a host rule alongside the built-in checks', function () {
        // The point of the extension seam: a host knows things this package
        // cannot, and should not have to fork the class to say so.
        $policy = GraphPolicy::untrusted()->addRule(function (array $schema): array {
            $nodes = $schema['graph']['nodes'] ?? [];

            return count($nodes) > 1 ? [ImportIssue::error('This host allows one node.')] : [];
        });

        expect($policy->inspect(gp_graph([gp_node('a', 'transform')])))->toBe([]);
        expect($policy->inspect(gp_graph([gp_node('a', 'transform'), gp_node('b', 'transform')])))->not->toBe([]);
    });

    it('assert() throws and carries EVERY issue, not just the first', function () {
        $policy = GraphPolicy::untrusted()->allowKinds(['transform']);

        $caught = null;

        try {
            $policy->assert(gp_graph([gp_node('a', 'api_request'), gp_node('b', 'webhook_out')]));
        } catch (UnsafeGraph $e) {
            $caught = $e;
        }

        expect($caught)->not->toBeNull();
        // One round-trip should tell an author everything that is wrong.
        expect($caught->issues)->toHaveCount(2);
    });

    it('is immutable, so a shared base policy cannot be widened by a caller', function () {
        // Every wither returns a clone. A host handing the same policy to two
        // call sites must not find one of them loosened by the other.
        $base = GraphPolicy::untrusted()->allowKinds(['transform']);
        $widened = $base->allowKinds(['transform', 'api_request']);

        expect($base->inspect(gp_graph([gp_node('a', 'api_request')])))->not->toBe([]);
        expect($widened->inspect(gp_graph([gp_node('a', 'api_request')])))->toBe([]);
    });
});
