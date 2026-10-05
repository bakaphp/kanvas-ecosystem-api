<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;

interface KnowledgeSource
{
    /** @return class-string<Model> */
    public function entityType(): string;

    /** @return list<KnowledgeDocument> */
    public function build(Model $entity): array;

    /**
     * The entity inside its tenant boundary, or null. Each source knows its own table's liveness
     * column, which is why the registry does not query the model itself.
     */
    public function find(int $entityId, int $appId, int $companyId): ?Model;

    public function isEnabledFor(Apps $app): bool;
}
