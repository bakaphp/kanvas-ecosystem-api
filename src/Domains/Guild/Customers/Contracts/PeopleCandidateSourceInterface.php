<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Contracts;

use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;

/**
 * Where bulk name matching gets its candidate rows. Implementations decide HOW plausible people are
 * found (search engine, SQL); the caller keeps the scoring, so found/not-found stays our own
 * shared-token rule rather than whatever relevance an engine happens to return.
 */
interface PeopleCandidateSourceInterface
{
    /**
     * Deliberately narrower than People::search()'s query_by, which also matches email and phone:
     * this path resolves a list of NAMES, and matching a name against an email address invents
     * hits. Every field MUST exist as a string in People::typesenseCollectionSchema() — Typesense
     * 400s on a query_by field it cannot find.
     */
    public const string QUERY_BY = 'name,firstname,lastname';

    /**
     * @param list<array{query: string, tokens: list<string>}> $terms
     *
     * @return Collection<int, People>
     */
    public function candidatesFor(Apps $app, Companies $company, array $terms): Collection;
}
