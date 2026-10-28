<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FileTextExtractor;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Throwable;

/**
 * Indexes the text documents attached to an entity under a scope, one document per file keyed by the
 * file uuid so a re-run replaces in place and a file delete can prune by that same key.
 */
final class EntityFilesIndexer
{
    public function __construct(private readonly FileTextExtractor $extractor = new FileTextExtractor())
    {
    }

    /**
     * @param (callable(Filesystem): bool)|null $filter
     * @return Collection<int, Filesystem>
     */
    public function indexableFiles(Model $entity, ?callable $filter = null): Collection
    {
        return $entity->files
            ->filter(fn (Filesystem $file): bool => $this->extractor->supports($file) && ($filter === null || $filter($file)))
            ->values();
    }

    /**
     * @param Collection<int, Filesystem> $files
     * @return int chunks written
     */
    public function index(
        Apps $app,
        KnowledgeScope $scope,
        string $sourceType,
        Collection $files,
    ): int {
        $indexer = KnowledgeComponents::indexer($app);
        $chunks = 0;

        foreach ($files as $file) {
            try {
                $content = $this->extractor->extract($file);
            } catch (Throwable) {
                // A corrupt or unreadable upload is a business condition, not a system fault.
                continue;
            }

            if (trim($content) === '') {
                continue;
            }

            $chunks += $indexer->indexDocument(
                $scope,
                $sourceType,
                $file->uuid,
                $content,
            );
        }

        return $chunks;
    }
}
