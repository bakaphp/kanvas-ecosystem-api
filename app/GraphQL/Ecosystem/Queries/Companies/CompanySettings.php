<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\CompaniesSettings;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class CompanySettings
{
    /**
     * all.
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function getAllSettings(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        /**
         * @var Builder
         */
        return  CompaniesSettings::select('name', 'value')
            ->notDeleted()
            ->fromCompany(auth()->user()->getCurrentCompany());
    }
}
