<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\DataTransferObject;

final readonly class KnowledgeDocument
{
    /** @param array<string, bool|float|int|string|null> $metadata */
    public function __construct(
        public string $id,
        public string $content,
        public array $metadata,
    ) {
    }
}
