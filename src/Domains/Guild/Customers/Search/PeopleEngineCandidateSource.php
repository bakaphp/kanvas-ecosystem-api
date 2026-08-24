<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Search;

use Baka\Search\Contracts\NameSearchInterface;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Customers\Models\People;
use Override;

/**
 * Giving each name its own query is why the shared-candidate-budget failure cannot happen here —
 * names have no cap to compete over, so a common surname can't evict the person actually asked about.
 */
class PeopleEngineCandidateSource implements PeopleCandidateSourceInterface
{
    use HydratesPeopleCandidates;

    public function __construct(
        private readonly NameSearchInterface $search,
    ) {
    }

    #[Override]
    public function candidatesFor(Apps $app, Companies $company, array $terms): Collection
    {
        return $this->hydrateCandidates($app, $company, $this->search->idsFor(
            new People(),
            $app,
            $company,
            self::QUERY_BY,
            $terms,
            NameSearchInterface::DEFAULT_CANDIDATES_PER_TERM,
        ));
    }
}
