<?php

declare(strict_types=1);

namespace FancyFlow\Exceptions;

use FancyFlow\Schema\ImportIssue;
use FancyFlow\Schema\ImportResult;

/**
 * A workflow document that could not be imported, reached somewhere that was
 * about to RUN it.
 *
 * A refused import ({@see ImportResult::refused()}) returns an empty graph, and
 * running an empty graph "succeeds" with nothing executed. The paths that
 * import in order to run (`FancyFlowManager::toGraph()` and the `subgraph`
 * executor) throw this instead, carrying the import's errors.
 */
final class UnreadableWorkflow extends FlowException
{
    /** @param list<ImportIssue> $issues */
    private function __construct(public readonly array $issues, string $message)
    {
        parent::__construct($message);
    }

    public static function from(ImportResult $result): self
    {
        $errors = $result->errors();
        $summary = implode('; ', array_map(static fn (ImportIssue $i): string => $i->message, $errors));

        return new self($errors, 'The workflow could not be imported: '.($summary !== '' ? $summary : 'unknown error'));
    }
}
