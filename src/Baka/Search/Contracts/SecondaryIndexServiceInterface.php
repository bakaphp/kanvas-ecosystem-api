<?php

declare(strict_types=1);

namespace Baka\Search\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Common contract for writing any Scout-searchable entity into a search index OTHER than its
 * normal `searchableAs()` one, bypassing Scout entirely — implemented per backend (Algolia,
 * Typesense, ...) since each SDK's upsert/delete shape differs.
 */
interface SecondaryIndexServiceInterface
{
    public function indexEntity(Model $entity, string $indexName): void;

    public function removeEntity(Model $entity, string $indexName): void;
}
