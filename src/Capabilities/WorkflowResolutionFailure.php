<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * Why a workflow reference could not be resolved.
 *
 * `missing` and `versionMismatch` are deliberately distinct. Collapsing them
 * into a bare null makes "no such workflow" indistinguishable from "that
 * workflow exists, but it is not the one you pinned" — and the second wants an
 * error naming both versions, because it is the interesting failure. Reporting
 * a mismatch as "not found" sends an author hunting for a workflow that is
 * sitting right there.
 *
 * The PHP twin of fancy-flow's `WorkflowResolutionFailure`.
 */
final class WorkflowResolutionFailure
{
    public const MISSING = 'missing';

    public const VERSION_MISMATCH = 'version-mismatch';

    /**
     * @param string   $reason    one of the class constants.
     * @param int|null $available the version the host actually holds, if any.
     * @param string|null $message host-supplied wording, preferred over the default.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?int $available = null,
        public readonly ?string $message = null,
    ) {}

    public static function missing(?string $message = null): self
    {
        return new self(self::MISSING, message: $message);
    }

    public static function versionMismatch(?int $available = null, ?string $message = null): self
    {
        return new self(self::VERSION_MISMATCH, $available, $message);
    }

    public function isVersionMismatch(): bool
    {
        return $this->reason === self::VERSION_MISMATCH;
    }
}
