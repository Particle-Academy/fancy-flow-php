<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;

/*
 * A node's inputs are also addressable by the SOURCE NODE'S ID — issue #8.
 *
 * Authors reach for node ids: every graph tool addresses nodes that way, so
 * `{{ n2.text }}` is the first thing written, by people and far more often by
 * assistants generating graphs. It resolved to nothing, and NOTHING FAILED —
 * `Expr` yields '' for an unresolvable path, so the node ran, the run reported
 * success, and the damage was output that was quietly wrong.
 *
 * The reporter's case: a `document` node with filename `{{ n3.title }}` and
 * content `{{ n3.transcript }}` wrote a file called `document.md` containing
 * the literal text of its own template, on a green run.
 *
 * `targetHandle` still covers reading something other than the immediate
 * predecessor and is unchanged. The model was never wrong; the obvious
 * spelling silently meant nothing.
 */

it('lets a node read its predecessor by node id as well as by port', function () {
    $seen = [];
    $registry = Builtin::executors();
    $registry->bind('src', fn () => ['text' => 'hello', 'title' => 'T']);
    $registry->bind('sink', function ($ctx) use (&$seen) {
        $seen = $ctx->inputs;

        return 1;
    });

    (new FlowRunner())->run(
        ffGraph([ffNode('n2', 'src'), ffNode('sink', 'sink')], [ffEdge('e1', 'n2', 'sink')]),
        $registry,
    );

    expect($seen['in'])->toBe(['text' => 'hello', 'title' => 'T'])
        ->and($seen['n2'])->toBe(['text' => 'hello', 'title' => 'T']);
});

it('does not shadow an explicit targetHandle, and adds no alias for it', function () {
    // An edge that named a handle said what it meant. A second key under the
    // source id would quietly widen a deliberate contract.
    $seen = [];
    $registry = Builtin::executors();
    $registry->bind('src', fn () => ['text' => 'hello']);
    $registry->bind('sink', function ($ctx) use (&$seen) {
        $seen = $ctx->inputs;

        return 1;
    });

    (new FlowRunner())->run(
        ffGraph(
            [ffNode('n2', 'src'), ffNode('sink', 'sink')],
            [ffEdge('e1', 'n2', 'sink', targetHandle: 'context')],
        ),
        $registry,
    );

    expect($seen['context'])->toBe(['text' => 'hello'])
        ->and($seen)->not->toHaveKey('n2')
        ->and($seen)->not->toHaveKey('in');
});
