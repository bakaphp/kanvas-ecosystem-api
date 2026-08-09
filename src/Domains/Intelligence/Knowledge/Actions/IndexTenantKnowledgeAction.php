<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

/**
 * Indexes a piece of raw tenant knowledge (an uploaded company policy/FAQ doc)
 * into the shared store as company-wide knowledge — entity_id = 0, so every
 * agent in the company can search it while no entity's private rows are touched.
 * Replace-by-source: re-indexing the same (sourceType, sourceName) swaps its
 * chunks in place.
 */
final class IndexTenantKnowledgeAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $sourceType,
        private readonly string $sourceName,
        private readonly string $content,
    ) {
    }

    public function execute(): int
    {
        return KnowledgeComponents::indexer($this->app)->indexTenantDocument(
            scope: KnowledgeScope::forTenant($this->app->getId(), $this->company->getId()),
            sourceType: $this->sourceType,
            sourceName: $this->sourceName,
            content: $this->content,
        );
    }
}
