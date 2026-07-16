<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * The outcome of {@see \FancyFlow\Workflow::import()} — a hydrated graph plus
 * the issues found. `ok` is true when no `error`-level issue was recorded
 * (in lenient mode errors are downgraded to warnings, so `ok` stays true).
 * Mirrors fancy-flow's `ImportResult`.
 */
final class ImportResult
{
    /** @param list<ImportIssue> $issues */
    public function __construct(
        public readonly bool $ok,
        public readonly FlowGraph $graph,
        public readonly array $issues = [],
    ) {}

    /** @return list<ImportIssue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i) => $i->isError()));
    }

    /** @return list<ImportIssue> */
    public function warnings(): array
    {
        return array_values(array_filter($this->issues, static fn (ImportIssue $i) => ! $i->isError()));
    }
}
