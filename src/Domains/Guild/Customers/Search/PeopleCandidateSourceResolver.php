<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Search;

use Baka\Search\NameSearchResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Contracts\PeopleCandidateSourceInterface;
use Kanvas\Guild\Customers\Models\People;

class PeopleCandidateSourceResolver
{
    public function for(Apps $app): PeopleCandidateSourceInterface
    {
        $search = new NameSearchResolver()->for($app, new People());

        return $search === null
            ? new PeopleSqlCandidateSource()
            : new PeopleEngineCandidateSource($search);
    }
}
