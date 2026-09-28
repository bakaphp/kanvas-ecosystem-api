<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\DataTransferObject;

final readonly class KnowledgeDocument
{
    /**
     * Metadata carries the Typesense record fields (apps_id, companies_id,
     * entity_type/entity_id, source_type/source_id, sourceType/sourceName, ...)
     * plus, once embedded, the 'embedding' float[] vector — hence mixed values.
     *
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $content,
        public array $metadata,
    ) {
    }
}
