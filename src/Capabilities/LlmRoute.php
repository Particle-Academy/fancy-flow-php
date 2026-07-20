<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * One route an {@see LlmClient} may choose between — a port id and the
 * description the model actually reads when deciding.
 *
 * The PHP twin of fancy-flow's `LlmRoute`.
 */
final class LlmRoute
{
    public function __construct(
        public readonly string $port,
        public readonly ?string $description = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            port: trim((string) ($raw['port'] ?? '')),
            description: isset($raw['description']) && $raw['description'] !== ''
                ? (string) $raw['description']
                : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->description === null
            ? ['port' => $this->port]
            : ['port' => $this->port, 'description' => $this->description];
    }
}
