<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeSourceRegistry;

final class KnowledgeIndexRequested
{
    use Dispatchable;

    public function __construct(public readonly KnowledgeEntity $entity)
    {
    }

    /**
     * The one gate every writer goes through: the entity's source decides whether its app indexes it.
     */
    public static function dispatchIfEnabled(Model $entity, ?KnowledgeSourceRegistry $sources = null): bool
    {
        $source = ($sources ?? new KnowledgeSourceRegistry())->for($entity::class);

        if ($source === null || ! $source->isEnabledFor($entity->app)) {
            return false;
        }

        self::dispatch(KnowledgeEntity::fromModel($entity));

        return true;
    }
}
