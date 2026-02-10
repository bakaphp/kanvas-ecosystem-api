<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Queries;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Stats\Actions\GetCapacityStatsAction;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductStatsQuery
{
    public function getCapacityStats(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $input = $args['input'];
        $productTypeSlug = $input['product_type_slug'] ?? null;
        $productIds = $input['product_ids'] ?? null;
        $warehouseId = isset($input['warehouse_id']) ? (int) $input['warehouse_id'] : null;

        $capacityStats = new GetCapacityStatsAction(
            $app,
            $company,
            $productTypeSlug,
            $productIds,
            $warehouseId
        )->execute();

        return [
            'max_capacity' => $capacityStats->maxCapacity,
            'available_capacity' => $capacityStats->availableCapacity,
            'occupied_capacity' => $capacityStats->occupiedCapacity,
            'occupancy_percentage' => $capacityStats->occupancyPercentage,
        ];
    }
}
