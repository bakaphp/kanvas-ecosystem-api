<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;

final class KnowledgeIndexRequested
{
    use Dispatchable;

    public function __construct(public readonly KnowledgeEntity $entity)
    {
    }
}
