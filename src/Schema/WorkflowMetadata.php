<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * Portable workflow metadata block of a WorkflowSchema v1 document.
 * Mirrors fancy-flow's `WorkflowMetadata`.
 */
final class WorkflowMetadata
{
    /** @param list<string>|null $tags */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?int $createdAt = null,
        public readonly ?int $updatedAt = null,
        public readonly ?string $author = null,
        public readonly ?array $tags = null,
    ) {}

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: isset($raw['id']) ? (string) $raw['id'] : null,
            name: isset($raw['name']) ? (string) $raw['name'] : null,
            description: isset($raw['description']) ? (string) $raw['description'] : null,
            createdAt: isset($raw['createdAt']) ? (int) $raw['createdAt'] : null,
            updatedAt: isset($raw['updatedAt']) ? (int) $raw['updatedAt'] : null,
            author: isset($raw['author']) ? (string) $raw['author'] : null,
            tags: isset($raw['tags']) && is_array($raw['tags'])
                ? array_values(array_map('strval', $raw['tags']))
                : null,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'author' => $this->author,
            'tags' => $this->tags,
        ], static fn ($v) => $v !== null);
    }
}
