<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;

interface KnowledgeSource
{
    /** @return class-string<Model> */
    public function entityType(): string;

    /** @return list<KnowledgeDocument> */
    public function build(Model $entity): array;
}
