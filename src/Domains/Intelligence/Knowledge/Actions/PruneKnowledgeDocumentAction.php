<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

/**
 * Takes a deleted file's chunks out of the knowledge base, so an agent stops answering from a
 * document that is gone.
 */
final class PruneKnowledgeDocumentAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly int $companiesId,
        private readonly string $fileUuid
    ) {
    }

    public function execute(): void
    {
        $store = KnowledgeComponents::store($this->app);

        // A file uuid is unique, so pruning both document kinds never touches another file.
        foreach ([IndexAgentDocumentsAction::SOURCE_TYPE, CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT] as $sourceType) {
            $store->deleteBySourceAcrossEntities(
                $this->app->getId(),
                $this->companiesId,
                $sourceType,
                $this->fileUuid,
            );
        }
    }
}
