<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * The vector search backend the embed_search executor queries. The default
 * {@see EmptyVectorStore} returns no matches; the Laravel layer binds a real
 * vector store.
 */
interface VectorStore
{
    /**
     * @return list<array{id?:string,score?:float,text?:string,metadata?:array<string,mixed>}>
     */
    public function search(string $query, int $topK = 5): array;
}
