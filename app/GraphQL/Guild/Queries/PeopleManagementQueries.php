<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Queries;

use Baka\Traits\KanvasScopesTrait;
use Kanvas\Enums\StateEnums;
use Kanvas\Guild\Customers\Models\People;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PeopleManagementQueries
{
    use KanvasScopesTrait;
    protected string $table = 'peoples';

    public function countByTag(mixed $root, array $request, GraphQLContext $context): int
    {
        $builder = People::whereHas('tags', function ($query) use ($request) {
            $query->where('tags.name', $request['tag']);
        });

        $builder->where('is_deleted', StateEnums::NO->getValue());
        $builder = $this->scopeFromCompany($builder);
        $builder = $this->scopeFromApp($builder);

        return $builder->count();
    }
}
