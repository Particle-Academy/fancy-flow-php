<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/** A deterministic {@see VectorStore} with no documents — always returns no matches. */
final class EmptyVectorStore implements VectorStore
{
    /** @var list<string> */
    public array $queries = [];

    public function search(string $query, int $topK = 5): array
    {
        $this->queries[] = $query;

        return [];
    }
}
