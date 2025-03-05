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
     */
    public static function filterByAttributes(string $channelId, array $attributes, array $priceRange = []): Builder
    {
        $query = Variants::query();

        //@todo this has to be configurable by the channel or company
        //unset($attributes['millage']);
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

            // Handle 'millage' as a special case
            if ($name === 'millage' && is_array($value) && count($value) === 2) {
                $query->where("$attrAlias.name", '=', 'odometer')
                      ->whereBetween("$alias.value", $value);
            } else {
                // Handle JSON and non-JSON values
                $query->where(function ($subQuery) use ($alias, $value) {
                    $subQuery->where(function ($jsonQuery) use ($alias, $value) {
                        $jsonQuery->whereRaw("JSON_VALID($alias.value) = 1")
                                  ->whereRaw("JSON_EXTRACT($alias.value, '$.en') = ?", [$value]);
                    })
                    ->orWhere("$alias.value", '=', $value);
                });
            }

            $index++;
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

        if ($priceRange) {
            $query->whereBetween('pvc.price', $priceRange);
        }

        return $query->select('products_variants.*')->distinct();
    }
}
