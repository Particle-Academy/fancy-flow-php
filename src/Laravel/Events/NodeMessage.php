<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/**
 * A node's own words to a PERSON. Mirrors the `node-message` RunEvent.
 *
 * Deliberately separate from {@see NodeStatusChanged}, whose `$text` is
 * diagnostic — "skipped", "resumed", an error string. A progress feed cannot be
 * asked to guess which of those are addressed to a human, so the two never share
 * a channel. `startingMsg` / `stoppingMsg` on a `FlowNode` are the source.
 *
 * `$phase` is `'start'` (announced just BEFORE the node runs) or `'end'`
 * (announced after it SUCCEEDS — a completion message printed after a failure
 * tells a person the opposite of what happened).
 *
 * ## Why this class exists later than the feature it carries
 *
 * `FlowRunner::announce()` emitted these from 0.25.0 and `FancyFlowManager`'s
 * bridge matched three event types, sending everything else to `default => null`
 * — so a node's message was computed, emitted, and dropped one layer before any
 * Laravel consumer could see it. The `$onEvent` callback did receive it, but on
 * the durable path the caller is `RunWorkflowJob` / `RunNodeJob`, so a host
 * never gets to pass one.
 *
 * The feature was therefore reachable from the in-process API and unreachable
 * from the queue-backed one — which is the only one a Laravel app runs workflows
 * on. Reported by a consumer who had both surfaces (a chat feed and a tray pill)
 * built and waiting for an event that could never arrive.
 *
 * That shape is worth naming: a capability wired to nothing is worse than an
 * absent one, because the docs promise it and nothing errors when it silently
 * does not happen.
 */
final class NodeMessage
{
    /**
     * @param 'start'|'end'|string $phase
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $nodeId,
        public readonly string $phase,
        public readonly string $message,
    ) {}
}
