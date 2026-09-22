<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/**
 * A routing value that is a bare string -- no `{{ }}` -- fails the node (#24).
 *
 * `branch.condition` and `switch_case.value` go through `Expr::evaluate()`,
 * which resolves `{{ }}` templates and nothing else. A bare `in.data.fits` came
 * back as the literal string `"in.data.fits"`, `Expr::truthy()` of a non-empty
 * string is true, and the branch routed `true` on EVERY run, whatever the data
 * said. `switch_case` used the bare string as a literal case key.
 *
 * A priority consumer hit it in production: an `llm_call` returned
 * `{"fits": false}`, a branch on bare `in.data.fits` routed true, and a deal was
 * created for a prospect the rubric had rejected. Nothing warned:
 * `RoutingDiagnostics::warnIfUnresolved()` only looks at a whole `{{ path }}`,
 * so it was structurally blind to a value that was never wrapped.
 *
 * The ruling is strict but loud. A bare string routing value is refused with a
 * message that names the fix. It is NOT resolved as a path -- `Expr` must not
 * start reading bare strings as paths, because every string config field flows
 * through it -- and it never becomes a constant `true`.
 *
 * What is unchanged, and pinned below so nobody widens the refusal:
 *  - strings containing properly closed expressions;
 *  - an empty or whitespace-only string (falsy, as it always was);
 *  - a non-string value (a JSON boolean, number or null).
 */
function bareRoutingGraph(string $kind, array $config, array $routes): FlowGraph
{
    $nodes = [new FlowNode('t', 'manual_trigger'), new FlowNode('r', $kind, config: $config)];
    $edges = [new FlowEdge('e0', 't', 'r')];

    foreach ($routes as $port => $target) {
        $nodes[] = new FlowNode($target, 'output');
        $edges[] = new FlowEdge("e-{$target}", 'r', $target, sourceHandle: $port);
    }

    return new FlowGraph($nodes, $edges);
}

/** @return array{ok: bool, error: ?string, ran: list<string>} */
function runBareRouting(string $kind, array $config, array $routes, array $trigger): array
{
    $result = (new FlowRunner)->run(
        bareRoutingGraph($kind, $config, $routes),
        Builtin::executors(),
        options: new RunOptions(initialInputs: ['t' => $trigger]),
    );

    $ran = array_map('strval', array_keys($result->outputs));
    sort($ran);

    return ['ok' => $result->ok, 'error' => $result->error, 'ran' => $ran];
}

function runBareBranch(mixed $condition, array $trigger = ['data' => ['fits' => false]]): array
{
    return runBareRouting('branch', ['condition' => $condition], ['true' => 'deal', 'false' => 'drop'], $trigger);
}

function runBareSwitch(mixed $value, array $trigger = ['kind' => 'b']): array
{
    return runBareRouting(
        'switch_case',
        ['value' => $value, 'cases' => ['a' => 'case_a', 'b' => 'case_b']],
        ['case_a' => 'on_a', 'case_b' => 'on_b', 'default' => 'fallback'],
        $trigger,
    );
}

it('refuses a bare branch condition instead of routing true on every run', function () {
    // The production shape: the data says NO, and the bare string said yes.
    $r = runBareBranch('in.data.fits');

    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('branch "r" condition "in.data.fits" is not an expression -- wrap it: {{ in.data.fits }}');
    // Neither side ran. The true side is the one that created the deal.
    expect($r['ran'])->toBe(['t']);
});

it('refuses the bare $props form from the report', function () {
    $r = runBareBranch('$props.deal_id');

    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('branch "r" condition "$props.deal_id" is not an expression -- wrap it: {{ $props.deal_id }}');
});

it('refuses a bare string even when truthy() would read it as false', function () {
    // The rule keys on WRAPPING, not on what the string happens to say. A fix
    // that refused only strings which would have routed true would pass the
    // production case and leave this one silently constant.
    $r = runBareBranch('false');

    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('branch "r" condition "false" is not an expression -- wrap it: {{ false }}');
});

it('names the trimmed value, so the suggested fix is copyable', function () {
    $r = runBareBranch("  in.data.fits \n");

    expect($r['error'])->toBe('branch "r" condition "in.data.fits" is not an expression -- wrap it: {{ in.data.fits }}');
});

it('routes the wrapped condition on the data, which is the fix the message names', function () {
    expect(runBareBranch('{{ in.data.fits }}'))->toBe(['ok' => true, 'error' => null, 'ran' => ['drop', 'r', 't']]);
    expect(runBareBranch('{{ in.data.fits }}', ['data' => ['fits' => true]]))
        ->toBe(['ok' => true, 'error' => null, 'ran' => ['deal', 'r', 't']]);
});

it('keeps an empty or whitespace-only condition falsy, as it always was', function () {
    foreach (['', '   ', "\n\t"] as $blank) {
        expect(runBareBranch($blank))->toBe(['ok' => true, 'error' => null, 'ran' => ['drop', 'r', 't']]);
    }
});

it('keeps a non-string condition exactly as it was', function () {
    expect(runBareBranch(true)['ran'])->toBe(['deal', 'r', 't']);
    expect(runBareBranch(false)['ran'])->toBe(['drop', 'r', 't']);
    expect(runBareBranch(1)['ran'])->toBe(['deal', 'r', 't']);
    expect(runBareBranch(0)['ran'])->toBe(['drop', 'r', 't']);
    expect(runBareBranch(null)['ran'])->toBe(['drop', 'r', 't']);
});

it('leaves properly closed interpolation to the existing rules', function () {
    // Interpolation is not this rule's business. Refusing it would re-route
    // graphs that build a condition as a string on purpose.
    expect(runBareBranch('urgent is {{ nope }}')['ok'])->toBeTrue();
    expect(runBareBranch('{{ in.data.fits }}-{{ in.data.fits }}')['ok'])->toBeTrue();
    expect(runBareSwitch('{{ in.kind }}-{{ in.kind }}')['ok'])->toBeTrue();
});

it('refuses a bare switch_case value instead of using it as a literal case key', function () {
    $r = runBareSwitch('in.kind');

    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('switch_case "r" value "in.kind" is not an expression -- wrap it: {{ in.kind }}');
    expect($r['ran'])->toBe(['t']);
});

it('refuses a bare switch_case value that happens to name a case', function () {
    // `"b"` matches the `b` case, so this routed to case_b on every run. It is
    // the same constant route the branch took, one step over.
    $r = runBareSwitch('b');

    expect($r['ok'])->toBeFalse();
    expect($r['error'])->toBe('switch_case "r" value "b" is not an expression -- wrap it: {{ b }}');
});

it('routes a wrapped switch_case value, and keeps blank and non-string values as they were', function () {
    expect(runBareSwitch('{{ in.kind }}')['ran'])->toBe(['on_b', 'r', 't']);
    expect(runBareSwitch('')['ran'])->toBe(['fallback', 'r', 't']);
    expect(runBareSwitch('  ')['ran'])->toBe(['fallback', 'r', 't']);
    expect(runBareSwitch(null)['ran'])->toBe(['fallback', 'r', 't']);
});
