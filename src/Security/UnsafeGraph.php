<?php

declare(strict_types=1);

namespace FancyFlow\Security;

use FancyFlow\Exceptions\FlowException;
use FancyFlow\Schema\ImportIssue;

/**
 * A graph was refused by a {@see GraphPolicy}.
 *
 * Carries every issue rather than only the first, because a caller rejecting an
 * upload wants to tell its author what is wrong once, not make them discover
 * the problems one round-trip at a time.
 */
final class UnsafeGraph extends FlowException
{
    /** @param list<ImportIssue> $issues */
    private function __construct(public readonly array $issues, string $message)
    {
        parent::__construct($message);
    }

    /** @param list<ImportIssue> $issues */
    public static function from(array $issues): self
    {
        $summary = implode('; ', array_map(static fn (ImportIssue $i): string => $i->message, $issues));

        return new self($issues, 'The graph was refused: '.$summary);
    }
}
