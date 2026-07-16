<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * The lifecycle status a node reports through `node-status` events. Mirrors
 * fancy-flow's `NodeRunStatus`.
 */
final class NodeStatus
{
    public const IDLE = 'idle';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const ERROR = 'error';
}
