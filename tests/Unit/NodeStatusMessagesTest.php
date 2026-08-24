<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\FlowNode;

/**
 * Per-node STATUS MESSAGES — a workflow narrating itself to a human.
 *
 * The PHP twin of `@particle-academy/fancy-flow` 0.49.0. A node may carry
 * `startingMsg` / `stoppingMsg`; the engine announces them around that node's
 * execution as `node-message` events: "Starting the deep analysis" before an
 * expensive step, "Analysis complete" once it lands.
 *
 * Two properties, both asserted rather than assumed:
 *
 * 1. **Opt-in per node.** A node with neither field announces nothing. Most
 *    nodes in a real graph are plumbing, and narrating all of them buries the
 *    two or three a person actually follows.
 *
 * 2. **Narration, not diagnostics.** Its own event type, kept out of
 *    `node-status.text` — which already carries "skipped", "resumed", "lane"
 *    and raw error strings. A progress feed cannot be asked to guess which of
 *    those are addressed to a person.
 *
 * The sharpest rule: `stoppingMsg` does NOT fire when the node throws.
 * "Analysis complete" after a crash tells a human the opposite of what
 * happened, in the part of the UI they trust most.
 */

/** Run a graph and return the narration, in order, as `phase:message`. */
function narrationOf(FancyFlow\Schema\FlowGraph $graph, FancyFlow\ExecutorRegistry $registry): array
{
    $said = [];
    (new FlowRunner())->run($graph, $registry, function (RunEvent $e) use (&$said) {
        if ($e->type === RunEvent::NODE_MESSAGE) {
            $said[] = "{$e->phase}:{$e->message}";
        }
    });

    return $said;
}

/** A `step` node that announces itself. FlowNode is readonly by design. */
function talkingNode(string $id, ?string $start = null, ?string $stop = null): FlowNode
{
    return new FlowNode(id: $id, type: 'step', startingMsg: $start, stoppingMsg: $stop);
}

/** A registry whose `step` kind runs $fn. */
function stepRegistry(callable $fn): FancyFlow\ExecutorRegistry
{
    $registry = Builtin::executors();
    $registry->bind('step', $fn);

    return $registry;
}

it('announces the start before the node runs and the end after it finishes', function () {
    $order = [];
    $registry = Builtin::executors();
    $registry->bind('step', function () use (&$order) {
        $order[] = 'executed';

        return 1;
    });

    $graph = ffGraph([talkingNode('a', 'Starting the deep analysis', 'Analysis complete')]);

    expect(narrationOf($graph, $registry))->toBe([
        'start:Starting the deep analysis',
        'end:Analysis complete',
    ]);
    expect($order)->toBe(['executed']);
});

it('says nothing for a node that declared no messages', function () {
    // Opt-in is the feature.
    expect(narrationOf(ffGraph([ffNode('quiet', 'step')]), stepRegistry(fn () => 1)))->toBe([]);
});

it('DOES NOT announce completion when the node throws', function () {
    // The one that matters: a completion message after a failure is the run
    // telling a person the opposite of what happened.
    $graph = ffGraph([talkingNode('a', 'Starting the deep analysis', 'Analysis complete')]);

    $said = narrationOf($graph, stepRegistry(function () {
        throw new RuntimeException('model refused');
    }));

    expect($said)->toBe(['start:Starting the deep analysis']);
});

it('ignores a message that is blank after trimming', function () {
    // An empty field is the shape a cleared editor input takes, and a blank
    // line in a progress feed cannot be told apart from a real message.
    $graph = ffGraph([talkingNode('a', '', '   ')]);

    expect(narrationOf($graph, stepRegistry(fn () => 1)))->toBe([]);
});

it('keeps narration out of node-status text', function () {
    $graph = ffGraph([talkingNode('a', 'Hello', 'Bye')]);

    $statusText = [];
    (new FlowRunner())->run($graph, stepRegistry(fn () => 1), function (RunEvent $e) use (&$statusText) {
        if ($e->type === RunEvent::NODE_STATUS && $e->text !== null) {
            $statusText[] = $e->text;
        }
    });

    expect($statusText)->not->toContain('Hello');
    expect($statusText)->not->toContain('Bye');
});

it('narrates a two-node run in execution order', function () {
    // The example this was built for: analyse, then save.
    $graph = ffGraph(
        [
            talkingNode('a', 'Starting the deep analysis', 'Analysis complete'),
            talkingNode('b', 'Saving report'),
        ],
        [ffEdge('e1', 'a', 'b')],
    );

    expect(narrationOf($graph, stepRegistry(fn () => 1)))->toBe([
        'start:Starting the deep analysis',
        'end:Analysis complete',
        'start:Saving report',
    ]);
});
