<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\Sources\LeadKnowledgeSource;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;

final class KnowledgeSourceRegistry
{
    /** @var array<class-string<Model>, KnowledgeSource> */
    private array $sources = [];

    /** @var array<string, class-string<Model>> */
    private array $aliases = [];

    /** @param iterable<KnowledgeSource>|null $sources */
    public function __construct(?iterable $sources = null)
    {
        foreach ($sources ?? [new LeadKnowledgeSource(), new LedgerKnowledgeSource()] as $source) {
            $this->sources[$source->entityType()] = $source;
            $this->aliases[strtolower(class_basename($source->entityType()))] = $source->entityType();
        }
    }

    /** @param class-string<Model> $entityType */
    public function for(string $entityType): ?KnowledgeSource
    {
        $entityType = $this->aliases[strtolower($entityType)] ?? $entityType;

        return $this->sources[$entityType] ?? null;
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return array_keys($this->aliases);
    }

    public function resolveAlias(
        string $alias,
        int $entityId,
        int $appId,
        int $companyId,
    ): ?Model {
        $entityType = $this->aliases[strtolower($alias)] ?? null;

        return $entityType === null
            ? null
            : $this->resolve($entityType, $entityId, $appId, $companyId);
    }

    /**
     * Resolve only an explicitly registered model and always apply tenant boundaries.
     * Channel namespaces are data, so they must never be instantiated directly.
     */
    public function resolve(
        string $entityType,
        int $entityId,
        int $appId,
        int $companyId
    ): ?Model {
        return $this->for($entityType)?->find($entityId, $appId, $companyId);
    }
}
