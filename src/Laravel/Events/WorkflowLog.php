<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Events;

/**
 * Something the run said on its log. Mirrors the `log` RunEvent.
 *
 * `$level` is `'info'`, `'warn'` or `'error'`. `$nodeId` is the node the line
 * is about, or null for a run-level line. `$detail` is the structured half, for
 * a consumer to act on rather than parse a sentence for: the undelivered-edge
 * warning carries `{edge, source, sourceHandle}`, and the routing warning carries
 * `{node, configKey, path, tookPort}` (both pinned by fancy-conformance
 * `flow/run-diagnostics`).
 *
 * ## Why this class exists later than the lines it carries
 *
 * The engine has emitted `log` events all along -- including the two warnings
 * for a graph that runs, reports success and delivers nothing down one path --
 * and `FancyFlowManager`'s bridge sent them to `default => null`. The in-process
 * `$onEvent` saw them; a Laravel app, which runs workflows on the queue and never
 * passes one, received nothing. It is the same shape {@see NodeMessage} was
 * added to fix, one arm over.
 */
final class WorkflowLog
{
    /**
     * @param 'info'|'warn'|'error'|string $level
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $level,
        public readonly string $message,
        public readonly ?string $nodeId = null,
        public readonly mixed $detail = null,
    ) {}
}
