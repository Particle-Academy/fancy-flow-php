<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

/**
 * The fancy-flow Live Contract — the PHP half of `flowLive` in
 * `@particle-academy/fancy-flow`.
 *
 * Declares which events describe a run's durable state and which client query
 * keys each one invalidates. A parity test on each side asserts the two lists
 * match, because drift between a mirror pair is SILENT: rename an event here
 * and nothing throws — the browser listens for a name nobody broadcasts, the
 * cache is never invalidated, and the run list quietly stops updating.
 *
 * ## What is deliberately absent
 *
 * `NodeStatusChanged` and `NodeOutput` fire per node, many times a second on a
 * wide graph. A node's log line is a STREAM, not a cache entry. Putting them
 * here would make a 40-node run invalidate the run list forty times while it
 * executes, each a re-fetch telling the UI nothing the stream had not already
 * delivered.
 *
 * ## Broadcast status
 *
 * The events in `FancyFlow\Laravel\Events` are dispatched IN-PROCESS; none
 * implements `ShouldBroadcast` today. This constant is therefore the agreed
 * vocabulary rather than a description of traffic already on the wire — a host
 * wanting live runs re-broadcasts under these names. Making them broadcast
 * natively is a separate change, because it turns on websocket traffic for
 * every consumer whether or not they asked for it.
 */
final class LiveContract
{
    public const NAMESPACE = 'flow';

    /**
     * Event name => the query keys it invalidates.
     *
     * @var array<string, list<list<string>>>
     */
    public const EVENTS = [
        'flow.run.created' => [['flow', 'runs']],
        'flow.run.updated' => [['flow', 'runs']],
        'flow.run.completed' => [['flow', 'runs']],
        // A run parking on a human step — its own event so a host can subscribe
        // to just the moment a form has to appear in front of somebody.
        'flow.run.awaiting' => [['flow', 'runs']],
        // Terminal, and rendered differently from a completed run — collapsing
        // the two would lose the distinction.
        'flow.run.failed' => [['flow', 'runs']],
    ];

    /**
     * The in-process event classes each contract event corresponds to, so a
     * host wiring broadcasting knows what to listen for.
     *
     * @var array<string, class-string>
     */
    public const SOURCES = [
        'flow.run.created' => Events\WorkflowStarted::class,
        'flow.run.completed' => Events\WorkflowFinished::class,
        'flow.run.failed' => Events\WorkflowFailed::class,
        'flow.run.updated' => Events\WorkflowSettled::class,
    ];

    /**
     * @return array{namespace: string, events: list<array{event: string, keys: list<list<string>>}>}
     */
    public static function toArray(): array
    {
        $events = [];

        foreach (self::EVENTS as $event => $keys) {
            $events[] = ['event' => $event, 'keys' => $keys];
        }

        return ['namespace' => self::NAMESPACE, 'events' => $events];
    }

    /** @return list<string> */
    public static function eventNames(): array
    {
        return array_keys(self::EVENTS);
    }
}
