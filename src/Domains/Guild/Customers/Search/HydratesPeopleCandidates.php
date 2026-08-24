<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;

trait HydratesPeopleCandidates
{
    /** What MatchesPeopleByName presents per match — both sources must load the same or they drift. */
    private const array CANDIDATE_COLUMNS = ['id', 'firstname', 'middlename', 'lastname', 'name'];

    protected function peopleCandidateQuery(Apps $app, Companies $company): Builder
    {
        return People::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->with([
                'contacts',
                'organizations' => fn ($q) => $q->select('organizations.id', 'name'),
            ]);
    }

    /**
     * Re-applies the tenant scopes on the way out. The engine already filtered by app + company, but
     * that filter lives in a remote index we do not control the freshness of — re-checking here means
     * a stale or misfiltered document can never turn into another tenant's contact in a reply.
     *
     * @param list<int|string> $ids
     *
     * @return Collection<int, People>
     */
    protected function hydrateCandidates(Apps $app, Companies $company, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection();
        }

        return $this->peopleCandidateQuery($app, $company)
            ->whereIn('id', array_map('intval', $ids))
            ->get(self::CANDIDATE_COLUMNS);
    }
}
