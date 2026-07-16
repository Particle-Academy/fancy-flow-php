<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A single problem found while importing a WorkflowSchema — an unknown kind,
 * a missing required config value, or a dangling edge. Mirrors fancy-flow's
 * `ImportIssue`. `level` is `error` or `warning`.
 */
final class ImportIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly ?string $nodeId = null,
        public readonly ?string $edgeId = null,
    ) {}

    public static function error(string $message, ?string $nodeId = null, ?string $edgeId = null): self
    {
        return new self(self::ERROR, $message, $nodeId, $edgeId);
    }

    public static function warning(string $message, ?string $nodeId = null, ?string $edgeId = null): self
    {
        return new self(self::WARNING, $message, $nodeId, $edgeId);
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'level' => $this->level,
            'message' => $this->message,
            'nodeId' => $this->nodeId,
            'edgeId' => $this->edgeId,
        ], static fn ($v) => $v !== null);
    }
}
