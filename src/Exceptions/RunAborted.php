<?php

declare(strict_types=1);

namespace FancyFlow\Exceptions;

/**
 * Thrown when an executor calls `$ctx->abort($reason)`. The runner catches it,
 * records the reason as the run error, and stops — mirroring the TS engine
 * where `abort()` throws and the node's try/catch turns it into a run error.
 */
final class RunAborted extends FlowException {}
