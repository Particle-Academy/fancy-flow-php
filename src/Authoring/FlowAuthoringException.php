<?php

declare(strict_types=1);

namespace FancyFlow\Authoring;

use RuntimeException;

/**
 * A deterministic, agent-facing authoring error — an unknown kind, a duplicate
 * id, an invalid port, a missing node/edge. The message is written to be read
 * by the connecting model and always says what to do next; tool handlers map it
 * straight to {@see \Laravel\Mcp\Response::error()}.
 */
final class FlowAuthoringException extends RuntimeException {}
