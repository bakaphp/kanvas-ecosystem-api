<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Stats\Repositories\ProductStatsRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductStatsQuery
{
    public function getCapacityStats(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $input = $args['input'];

        $companiesId = $user->isAppOwner()
            ? (isset($input['companies_id']) ? (int) $input['companies_id'] : null)
            : (int) $user->getCurrentCompany()->getId();

        $capacityStats = ProductStatsRepository::getCapacityStats(
            $app,
            $company,
            $input['product_type_slug'] ?? null,
            $input['product_ids'] ?? null,
            isset($input['warehouse_id']) ? (int) $input['warehouse_id'] : null,
            $companiesId,
        );

        return [
            'max_capacity' => $capacityStats->maxCapacity,
            'available_capacity' => $capacityStats->availableCapacity,
            'occupied_capacity' => $capacityStats->occupiedCapacity,
            'occupancy_percentage' => $capacityStats->occupancyPercentage,
        ];
    }
}
