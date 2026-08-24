<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Search;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Search\MatchesBulkNameTerms;
use Override;

/**
 * The pre-engine path: a `LIKE '%token%'` scan of the tenant's contacts. Retained as the fallback for
 * apps with no engine configured, and as the baseline to diff engine verdicts against before trusting
 * the switch. It cannot use an index, so it is the thing this whole module exists to stop doing.
 */
class PeopleSqlCandidateSource implements PeopleCandidateSourceInterface
{
    use HydratesPeopleCandidates;
    use MatchesBulkNameTerms;

    #[Override]
    public function candidatesFor(Apps $app, Companies $company, array $terms): Collection
    {
        if ($terms === []) {
            return new Collection();
        }

        return $this->peopleCandidateQuery($app, $company)
            ->where(function (BuilderContract $query) use ($terms): void {
                foreach ($terms as $term) {
                    $this->applyBulkCandidateFilter($query, $term['tokens'], ['name', 'firstname', 'lastname']);
                }
            })
            ->limit(static::BULK_MAX_CANDIDATE_ROWS)
            ->get(self::CANDIDATE_COLUMNS);
    }
}
