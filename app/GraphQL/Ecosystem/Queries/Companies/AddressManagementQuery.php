<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Companies\Models\CompaniesAddress;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AddressManagementQuery
{
    /**
     * all.
     */
    public function getAllCompanyAddress(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $companiesId = auth()->user()->currentCompanyId();

        $query = CompaniesAddress::select(
            'companies_address.id', // User ID
            'companies_address.is_default',
            'companies_address.fullname',
            'companies_address.phone',
            'companies_address.address',
            'companies_address.address_2',
            'companies_address.city',
            'companies_address.state',
            'companies_address.zip',
            'companies_address.countries_id',
            'companies_address.city_id',
            'companies_address.state_id',
            'companies_address.created_at',
            'companies_address.updated_at'
        )
        ->join(
            'companies',
            'companies.id',
            'companies_address.companies_id'
        )
        ->leftJoin(
            'countries_cities',
            'countries_cities.id',
            'companies_address.city_id'
        )
        ->leftJoin(
            'countries_states',
            'countries_states.id',
            'companies_address.state_id'
        )
        ->leftJoin(
            'countries',
            'countries.id',
            'companies_address.countries_id'
        );

        $query->when(isset($root->companies_id), function ($query) use ($root) {
            $query->where('companies_address.companies_id', $root->companies_id);
        });

        $query->when(! isset($root->companies_id), function ($query) use ($companiesId) {
            $query->where('companies_address.companies_id', $companiesId);
        });

        $query->where('companies_address.is_deleted', StateEnums::NO->getValue())
        ->groupBy('companies_address.id');

        return $query;
    }
}
