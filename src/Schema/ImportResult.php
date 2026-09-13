<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * The outcome of {@see \FancyFlow\Workflow::import()} — a hydrated graph plus
 * the issues found. `ok` is true when no `error`-level issue was recorded
 * (lenient mode downgrades an unknown kind to a warning, but never an
 * unsupported schema version, which always fails with an empty graph).
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

    /**
     * Whether the importer refused the DOCUMENT, rather than reading it and
     * finding problems.
     *
     * A refusal (not an object, or not `version: 1`) returns `ok: false` with
     * an EMPTY graph: nothing was read. An import that read the graph and found
     * an error (a connectivity error, say, or an unknown kind in strict mode)
     * returns that graph alongside `ok: false`. Code that goes on to RUN an
     * import must refuse the first, or it runs nothing and reports success.
     */
    public function refused(): bool
    {
        return ! $this->ok && $this->graph->nodes === [] && $this->graph->edges === [];
    }
}
