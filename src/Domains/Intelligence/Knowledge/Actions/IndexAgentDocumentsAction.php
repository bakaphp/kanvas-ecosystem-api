<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Actions;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Services\EntityFilesIndexer;

/**
 * Indexes the documents staff attached to an agent as that agent's knowledge.
 */
final class IndexAgentDocumentsAction
{
    /** sourceType stamped on every agent-uploaded knowledge chunk (write + prune share this). */
    public const string SOURCE_TYPE = 'agent_document';

    public function __construct(
        private readonly Agent $agent
    ) {
    }

    /**
     * @return array{documents: int, chunks: int}
     */
    public function execute(): array
    {
        $filesIndexer = new EntityFilesIndexer();
        $textFiles = $filesIndexer->indexableFiles(
            $this->agent,
            fn (Filesystem $file): bool => $this->isStaffUpload($file)
        );

        if ($textFiles->isEmpty()) {
            return ['documents' => 0, 'chunks' => 0];
        }

        // Scoped to this agent, not the company: an upload to one agent is not another's to retrieve.
        $chunks = $filesIndexer->index(
            $this->agent->app,
            KnowledgeScope::forModel($this->agent),
            self::SOURCE_TYPE,
            $textFiles
        );

        return ['documents' => $textFiles->count(), 'chunks' => $chunks];
    }

    /**
     * Only files a person attached, never the agent's own user feeding itself knowledge. Customer
     * files never reach an Agent (they land on a Message or a Lead), so this is a second guard.
     */
    private function isStaffUpload(Filesystem $file): bool
    {
        $uploaderId = $file->users_id;

        return $uploaderId > 0 && $uploaderId !== (int) ($this->agent->user?->getId() ?? 0);
    }
}
