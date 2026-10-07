<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;

/*
 * A nested run's node events reach the host, not just its checkpoints.
 *
 * fancy-flow-php#25. Both executors that run a nested graph -- `for_each` over
 * its item lane, and `subgraph` over an inline graph -- forwarded ONLY
 * `node-checkpoint` and dropped everything else on the floor:
 *
 *     if ($event->type === RunEvent::NODE_CHECKPOINT) { … }
 *
 * So a node inside the lane could report status, emit a message, or log, and the
 * host saw none of it. For a `for_each` that is the whole point of iterating in
 * the graph rather than looping inside one executor: per-item progress is one of
 * the things you traded the simple implementation for, and it was silently
 * unavailable.
 *
 * Checkpoints survived because they are what RESUME needs, so the durable tests
 * passed throughout. Nothing asserted on the events a human watches.
 */

/** Collect everything the parent run emitted. */
function nestedEvents(callable $bindBody, array $nodes, array $edges): array
{
    $seen = [];

    $executors = Builtin::executors()
        ->bind('source', fn (): array => ['items' => [1, 2]])
        ->bind('body', $bindBody)
        ->bind('after', fn (ExecutionContext $ctx) => $ctx->input());

    (new FlowRunner)->run(
        ffGraph($nodes, $edges),
        $executors,
        onEvent: function (RunEvent $event) use (&$seen): void {
            $seen[] = $event;
        },
        options: new RunOptions(initialInputs: []),
    );

    return $seen;
}

function forEachGraphNodes(): array
{
    return [
        ffNode('source', 'source'),
        ffNode('each', 'for_each', ['source' => '{{ in.items }}'], ['item', 'done']),
        ffNode('body', 'body'),
        ffNode('after', 'after'),
    ];
}

function forEachGraphEdges(): array
{
    return [
        ffEdge('e1', 'source', 'each'),
        ffEdge('e2', 'each', 'body', sourceHandle: 'item'),
        ffEdge('e3', 'each', 'after', sourceHandle: 'done'),
    ];
}

it('forwards a node-status from inside a for_each lane, addressed per item', function (): void {
    $events = nestedEvents(
        function (ExecutionContext $ctx): array {
            $ctx->emit(RunEvent::nodeStatus($ctx->node->id, 'running', 'scoring'));

            return ['ok' => true];
        },
        forEachGraphNodes(),
        forEachGraphEdges(),
    );

    $statuses = array_values(array_filter(
        $events,
        static fn (RunEvent $e): bool => $e->type === RunEvent::NODE_STATUS && $e->text === 'scoring',
    ));

    // One per item, and each addressed to the item it came from -- an
    // unaddressed duplicate would be indistinguishable from the other item's.
    expect($statuses)->toHaveCount(2);

    $ids = array_map(static fn (RunEvent $e): ?string => $e->nodeId, $statuses);
    expect($ids)->toBe(['each/0/body', 'each/1/body']);
});

it('forwards a node-message from inside a for_each lane', function (): void {
    $events = nestedEvents(
        function (ExecutionContext $ctx): array {
            $ctx->emit(RunEvent::nodeMessage($ctx->node->id, 'thinking', 'half way'));

            return ['ok' => true];
        },
        forEachGraphNodes(),
        forEachGraphEdges(),
    );

    $messages = array_values(array_filter(
        $events,
        static fn (RunEvent $e): bool => $e->type === RunEvent::NODE_MESSAGE,
    ));

    expect($messages)->toHaveCount(2);
    expect($messages[0]->message)->toBe('half way');
    expect($messages[0]->nodeId)->toBe('each/0/body');
});

it('forwards a log from inside a for_each lane', function (): void {
    $events = nestedEvents(
        function (ExecutionContext $ctx): array {
            $ctx->emit(RunEvent::log('warning', 'rate limited', $ctx->node->id));

            return ['ok' => true];
        },
        forEachGraphNodes(),
        forEachGraphEdges(),
    );

    $logs = array_values(array_filter(
        $events,
        static fn (RunEvent $e): bool => $e->type === RunEvent::LOG && $e->message === 'rate limited',
    ));

    expect($logs)->toHaveCount(2);
    expect($logs[0]->nodeId)->toBe('each/0/body');
});

it('still forwards checkpoints, which is what resume reads', function (): void {
    // The one event type that DID survive. Asserted so a fix that forwards
    // everything else cannot quietly stop forwarding this one.
    $events = nestedEvents(
        fn (ExecutionContext $ctx): array => ['ok' => true],
        forEachGraphNodes(),
        forEachGraphEdges(),
    );

    $checkpoints = array_values(array_filter(
        $events,
        static fn (RunEvent $e): bool => $e->type === RunEvent::NODE_CHECKPOINT
            && str_starts_with((string) $e->nodeId, 'each/'),
    ));

    expect(count($checkpoints))->toBeGreaterThanOrEqual(2);
});

it('does NOT forward the nested run lifecycle as if it were the parent run', function (): void {
    // A lane's run-start/run-end are not the parent's. Forwarding them would
    // tell a host the whole workflow started twice and finished before it did.
    $events = nestedEvents(
        fn (ExecutionContext $ctx): array => ['ok' => true],
        forEachGraphNodes(),
        forEachGraphEdges(),
    );

    $starts = array_filter($events, static fn (RunEvent $e): bool => $e->type === RunEvent::RUN_START);
    $ends = array_filter($events, static fn (RunEvent $e): bool => $e->type === RunEvent::RUN_END);

    expect(count($starts))->toBe(1);
    expect(count($ends))->toBe(1);
});
