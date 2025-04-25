<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Inventory\Variants\Models\Variants;

class VariantsChannelRepository
{
    /**
     * this is a temp solution to filter variants / product by attributes
     * we should aim for shopify query implementation in the future.
     * @psalm-suppress MissingClosureParamType
     * @psalm-suppress UndefinedMethod
     * @psalm-suppress InvalidArgument
     */
    public static function filterByAttributes(string $channelId, array $attributes, array $priceRange = []): Builder
    {
        $query = Variants::query();

        //@todo this has to be configurable by the channel or company
        $millage = $attributes['millage'] ?? null;
        unset($attributes['millage']);
        unset($attributes['price']);
        $index = 1;

        foreach ($attributes as $name => $value) {
            $alias = 'pva' . $index; // Aliases for each join
            $attrAlias = 'a' . $index;

            // Join products_variants_attributes table
            $query->join(
                "products_variants_attributes as $alias",
                'products_variants.id',
                '=',
                "$alias.products_variants_id"
            );

            // Join attributes table
            $query->join(
                "attributes as $attrAlias",
                "$alias.attributes_id",
                '=',
                "$attrAlias.id"
            );

            // Add where conditions
            $query->where("$attrAlias.name", '=', $name);

            // Handle JSON and non-JSON values
            $query->where(function ($subQuery) use ($alias, $value) {
                $subQuery->where(function ($jsonQuery) use ($alias, $value) {
                    $jsonQuery->whereRaw("JSON_VALID($alias.value) = 1")
                              ->whereRaw("JSON_EXTRACT($alias.value, '$.en') = ?", [$value]);
                })
                ->orWhere("$alias.value", '=', $value);
            });

            $index++;
        }

        // Handle millage separately with the same JSON handling logic
        if ($millage !== null && is_array($millage) && count($millage) === 2) {
            $alias = 'pva' . $index;
            $attrAlias = 'a' . $index;

            $query->join(
                "products_variants_attributes as $alias",
                'products_variants.id',
                '=',
                "$alias.products_variants_id"
            );

            $query->join(
                "attributes as $attrAlias",
                "$alias.attributes_id",
                '=',
                "$attrAlias.id"
            );

            $query->where("$attrAlias.name", '=', 'odometer');

            // Apply the range condition with JSON handling
            $query->where(function ($subQuery) use ($alias, $millage) {
                // For JSON values
                $subQuery->where(function ($jsonQuery) use ($alias, $millage) {
                    $jsonQuery->whereRaw("JSON_VALID($alias.value) = 1")
                              ->whereRaw("CAST(JSON_EXTRACT($alias.value, '$.en') AS DECIMAL(10,2)) >= ?", [$millage[0]])
                              ->whereRaw("CAST(JSON_EXTRACT($alias.value, '$.en') AS DECIMAL(10,2)) <= ?", [$millage[1]]);
                })
                // For non-JSON values
                ->orWhere(function ($rangeQuery) use ($alias, $millage) {
                    $rangeQuery->where("$alias.value", '>=', $millage[0])
                               ->where("$alias.value", '<=', $millage[1]);
                });
            });

            //$index++;
        }

        $query->join(
            'products_variants_channels as pvc',
            'products_variants.id',
            '=',
            'pvc.products_variants_id'
        );
        $query->join('channels as c', 'pvc.channels_id', '=', 'c.id');

        $query->where('c.uuid', '=', $channelId)
              ->where('products_variants.is_deleted', '=', 0)
              ->where('pvc.is_published', '=', 1)
              ->where('pvc.is_deleted', '=', 0)
              ->where('c.is_deleted', '=', 0);

        // Apply price range with JSON handling
        if ($priceRange && count($priceRange) === 2) {
            $query->where(function ($priceQuery) use ($priceRange) {
                // Handle regular numeric price
                $priceQuery->whereBetween('pvc.price', $priceRange)
                // Handle JSON price format
                ->orWhere(function ($jsonPriceQuery) use ($priceRange) {
                    $jsonPriceQuery->whereRaw('JSON_VALID(pvc.price) = 1')
                                  ->whereRaw("CAST(JSON_EXTRACT(pvc.price, '$.en') AS DECIMAL(10,2)) >= ?", [$priceRange[0]])
                                  ->whereRaw("CAST(JSON_EXTRACT(pvc.price, '$.en') AS DECIMAL(10,2)) <= ?", [$priceRange[1]]);
                });
            });
        }

        return $query->select('products_variants.*')->distinct();
    }
}
