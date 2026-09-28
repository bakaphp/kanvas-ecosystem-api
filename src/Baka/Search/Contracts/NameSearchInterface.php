<?php

declare(strict_types=1);

namespace Baka\Search\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

/**
 * Resolves a batch of names to record ids through the app's search engine. Ids only — hydration and
 * scoring stay with the caller, because those are entity-specific (which relations to load, which
 * status counts as open) while the query itself is not.
 */
interface NameSearchInterface
{
    /** Rows to pull per name before scoring — wider than the match budget so the scorer decides. */
    public const int DEFAULT_CANDIDATES_PER_TERM = 20;

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     * @param string $queryBy comma-separated index fields to match against
     *
     * @return list<string> record ids, unordered and deduplicated
     */
    public function idsFor(
        Model $model,
        Apps $app,
        Companies $company,
        string $queryBy,
        array $terms,
        int $perTerm,
    ): array;
}
