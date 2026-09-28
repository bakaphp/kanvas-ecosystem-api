<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\DataTransferObject;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class KnowledgeEntity
{
    /** @param class-string<Model> $type */
    public function __construct(
        public string $type,
        public int $id,
        public int $appId,
        public int $companyId,
    ) {
        if ($this->id < 1 || $this->appId < 1 || $this->companyId < 1) {
            throw new InvalidArgumentException('Knowledge entity and tenant identifiers must be positive.');
        }
    }

    public static function fromModel(Model $entity): self
    {
        return new self(
            type: $entity::class,
            id: (int) $entity->getKey(),
            appId: (int) $entity->getAttribute('apps_id'),
            companyId: (int) $entity->getAttribute('companies_id'),
        );
    }

    public function key(): string
    {
        return implode(':', [$this->type, $this->appId, $this->companyId, $this->id]);
    }
}
