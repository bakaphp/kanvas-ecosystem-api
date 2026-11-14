<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Knowledge\Services\CompanyKnowledgeService;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;

/**
 * Takes a deleted file's chunks out of the knowledge base, so an agent stops answering from a
 * document that is gone.
 */
final class PruneKnowledgeDocumentAction
{
    public function __construct(
        private readonly Filesystem $file
    ) {
    }

    public function execute(): void
    {
        $app = $this->file->app;

        // A file uuid is unique, so pruning both document kinds never touches another file.
        foreach ([IndexAgentDocumentsAction::SOURCE_TYPE, CompanyKnowledgeService::SOURCE_TYPE_DOCUMENT] as $sourceType) {
            KnowledgeComponents::store($app)->deleteBySourceAcrossEntities(
                $app->getId(),
                $this->file->companies_id,
                $sourceType,
                $this->file->uuid,
            );
        }
    }
}
