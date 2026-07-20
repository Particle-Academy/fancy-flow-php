<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * What the model decided. The PHP twin of fancy-flow's `LlmRouteChoice`.
 *
 * `$reason` travels down the chosen port with the value, so a completed run
 * explains itself without the model call being replayed.
 */
final class LlmRouteChoice
{
    public function __construct(
        public readonly string $port,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Read a choice out of a decoded structured-output payload. Tolerant about
     * shape because every provider spells JSON slightly differently — but never
     * about validity: an unrecognised port is the caller's problem to catch
     * ({@see \FancyFlow\Nodes\Ai\LlmRouterExecutor} does), not something to
     * paper over here.
     *
     * @param array<string,mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $port = $raw['port'] ?? $raw['route'] ?? '';
        $reason = $raw['reason'] ?? $raw['why'] ?? null;

        return new self(
            port: is_scalar($port) ? trim((string) $port) : '',
            reason: is_scalar($reason) && (string) $reason !== '' ? (string) $reason : null,
        );
    }
}
