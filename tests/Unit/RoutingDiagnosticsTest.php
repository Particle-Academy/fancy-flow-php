<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/**
 * A routing decision made on a path that DID NOT RESOLVE must say so.
 *
 * `branch` resolves its condition and asks `Expr::truthy()`. An unresolvable
 * path yields `null`, `null` is falsy, and the run takes `false` — silently, and
 * for a reason that has nothing to do with the data. `switch_case` does the same
 * one step over, falling to `default`.
 *
 * Half the graph then never runs and the run reports success. It is the `''`
 * collapse again, except that here it changes the ROUTE rather than the text,
 * which is worse: an empty string is at least visible in the output.
 *
 * Found by `flabs`. An agent built a correct triage graph whose urgency check
 * referenced a field that did not resolve, so a request reporting total payment
 * failure was routed as non-urgent. The graph was right and the path was wrong,
 * and nothing anywhere said so.
 *
 * **Routing is deliberately unchanged.** The discipline below is that the
 * warning fires only for an ABSENT path — never for a condition that was
 * legitimately false, which is the overwhelming majority of branches ever run.
 */
function routeWarnings($result): array
{
    $out = [];

    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn' && ($event->detail['path'] ?? null) !== null) {
            $out[] = $event->message;
        }
    }

    return $out;
}

function runBranch(string $condition, array $input): array
{
    $graph = new FlowGraph(
        [
            new FlowNode('b', 'branch', config: ['condition' => $condition]),
            new FlowNode('t', 'log', config: ['message' => 'true side']),
            new FlowNode('f', 'log', config: ['message' => 'false side']),
        ],
        [
            new FlowEdge('e1', 'b', 't', sourceHandle: 'true'),
            new FlowEdge('e2', 'b', 'f', sourceHandle: 'false'),
        ],
    );

    $result = (new FlowRunner)->run(
        $graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: ['b' => $input]),
    );

    $took = null;
    foreach ($result->events as $event) {
        if ($event->type === 'node-output' && $event->nodeId === 'b') {
            $took = $event->portId;
        }
    }

    return ['warnings' => routeWarnings($result), 'took' => $took];
}

it('warns when a branch condition path resolves to NOTHING', function () {
    $r = runBranch('{{ urgent }}', ['other' => true]);

    expect($r['took'])->toBe('false');
    expect($r['warnings'])->toHaveCount(1);
    expect($r['warnings'][0])->toContain('resolved to NOTHING');
    expect($r['warnings'][0])->toContain('urgent');
    // The distinction spelled out, because it is the whole point.
    expect($r['warnings'][0])->toContain('not the same as a false condition');
});

it('does NOT warn when the condition is legitimately false', function () {
    // The discipline. Most branches ever run take `false` honestly, and a
    // warning on those is noise -- which is how a real warning stops being read.
    foreach ([false, 0, '', 'false', '0', []] as $falsy) {
        $r = runBranch('{{ urgent }}', ['urgent' => $falsy]);

        expect($r['took'])->toBe('false');
        expect($r['warnings'])->toBe([], 'warned on a legitimately false '.gettype($falsy));
    }
});

it('does NOT warn when the condition resolves to NULL', function () {
    // `null` is a RESOLVED value here -- the key exists. Distinct from absent,
    // which is exactly the distinction `tryResolvePath` was added for.
    $r = runBranch('{{ urgent }}', ['urgent' => null]);

    expect($r['took'])->toBe('false');
    expect($r['warnings'])->toBe([]);
});

it('does NOT warn when the branch is taken', function () {
    $r = runBranch('{{ urgent }}', ['urgent' => true]);

    expect($r['took'])->toBe('true');
    expect($r['warnings'])->toBe([]);
});

it('does not warn on a condition that mixes text with an expression', function () {
    // Not a routing question. A condition built as a STRING is being used as
    // one, and an unresolved fragment there is the interpolation case.
    $r = runBranch('urgent is {{ nope }}', ['x' => 1]);

    expect($r['warnings'])->toBe([]);
});

it('does not report the {{a}}{{b}} corner as a missing field', function () {
    // The whole template is one expression whose path spans an inner `}}{{`.
    // That is a malformed template, not an absent field, and saying "the path
    // names no field" would send the reader somewhere useless.
    $r = runBranch('{{ a }}{{ b }}', ['a' => 1, 'b' => 2]);

    expect($r['warnings'])->toBe([]);
});

it('carries the path and the port taken as structured detail', function () {
    $graph = new FlowGraph(
        [new FlowNode('b', 'branch', config: ['condition' => '{{ missing.thing }}'])],
        [],
    );

    $result = (new FlowRunner)->run(
        $graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: ['b' => ['present' => 1]]),
    );

    $detail = null;
    foreach ($result->events as $event) {
        if ($event->type === 'log' && $event->level === 'warn') {
            $detail = $event->detail;
        }
    }

    expect($detail['path'])->toBe('missing.thing');
    expect($detail['configKey'])->toBe('condition');
    expect($detail['tookPort'])->toBe('false');
});

it('warns when a switch_case value resolves to nothing and falls to default', function () {
    // The same shape one step over: no resolution -> '' -> matches no case ->
    // `default`, indistinguishable from a value that genuinely matched nothing.
    $graph = new FlowGraph(
        [
            new FlowNode('s', 'switch_case', config: [
                'value' => '{{ lane }}',
                'cases' => ['billing' => 'case_a'],
            ]),
        ],
        [],
    );

    $result = (new FlowRunner)->run(
        $graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: ['s' => ['something_else' => 'billing']]),
    );

    $warnings = routeWarnings($result);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('`value` resolved to NOTHING');
    expect($warnings[0])->toContain('default');
});

it('does NOT warn when a switch_case value resolves but matches no case', function () {
    // A real value that matches nothing is what `default` is FOR. Warning here
    // would fire on the node's intended behaviour.
    $graph = new FlowGraph(
        [
            new FlowNode('s', 'switch_case', config: [
                'value' => '{{ lane }}',
                'cases' => ['billing' => 'case_a'],
            ]),
        ],
        [],
    );

    $result = (new FlowRunner)->run(
        $graph,
        Builtin::executors(),
        options: new RunOptions(initialInputs: ['s' => ['lane' => 'something-unmapped']]),
    );

    expect(routeWarnings($result))->toBe([]);
});
