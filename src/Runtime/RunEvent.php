<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * A single event in a run's stream. The PHP twin of fancy-flow's `RunEvent`
 * discriminated union — one class with a `type` tag and the union of payload
 * fields. Build them with the named factories; serialize with {@see toArray()}.
 *
 * Types: `run-start`, `node-status`, `node-output`, `log`, `run-end`, `run-error`.
 */
final class RunEvent
{
    public const RUN_START = 'run-start';
    public const NODE_STATUS = 'node-status';
    public const NODE_OUTPUT = 'node-output';
    public const LOG = 'log';
    public const RUN_END = 'run-end';
    public const RUN_ERROR = 'run-error';

    private function __construct(
        public readonly string $type,
        public readonly ?string $nodeId = null,
        public readonly ?string $status = null,
        public readonly ?string $text = null,
        public readonly ?string $portId = null,
        public readonly mixed $value = null,
        public readonly ?string $level = null,
        public readonly ?string $message = null,
        public readonly mixed $detail = null,
        public readonly ?bool $ok = null,
        public readonly ?string $error = null,
    ) {}

    public static function runStart(): self
    {
        return new self(self::RUN_START);
    }

    public static function nodeStatus(string $nodeId, string $status, ?string $text = null): self
    {
        return new self(self::NODE_STATUS, nodeId: $nodeId, status: $status, text: $text);
    }

    public static function nodeOutput(string $nodeId, string $portId, mixed $value): self
    {
        return new self(self::NODE_OUTPUT, nodeId: $nodeId, portId: $portId, value: $value);
    }

    public static function log(string $level, string $message, ?string $nodeId = null, mixed $detail = null): self
    {
        return new self(self::LOG, nodeId: $nodeId, level: $level, message: $message, detail: $detail);
    }

    public static function runEnd(bool $ok): self
    {
        return new self(self::RUN_END, ok: $ok);
    }

    public static function runError(string $error): self
    {
        return new self(self::RUN_ERROR, error: $error);
    }

    /** @return array<string,mixed> A shape-matched serialization of the active union arm. */
    public function toArray(): array
    {
        return match ($this->type) {
            self::RUN_START => ['type' => $this->type],
            self::NODE_STATUS => array_filter(
                ['type' => $this->type, 'nodeId' => $this->nodeId, 'status' => $this->status, 'text' => $this->text],
                static fn ($v) => $v !== null,
            ),
            self::NODE_OUTPUT => ['type' => $this->type, 'nodeId' => $this->nodeId, 'portId' => $this->portId, 'value' => $this->value],
            self::LOG => array_filter(
                ['type' => $this->type, 'nodeId' => $this->nodeId, 'level' => $this->level, 'message' => $this->message, 'detail' => $this->detail],
                static fn ($v) => $v !== null,
            ),
            self::RUN_END => ['type' => $this->type, 'ok' => $this->ok],
            self::RUN_ERROR => ['type' => $this->type, 'error' => $this->error],
            default => ['type' => $this->type],
        };
    }
}
