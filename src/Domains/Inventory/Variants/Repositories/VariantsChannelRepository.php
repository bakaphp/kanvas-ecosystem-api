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

        unset($attributes['price']);
        $index = 1;
        foreach ($attributes as $name => $value) {
            $alias = 'pva' . $index; // Start with pva2 since pva1 is already used
            $query->join("products_variants_attributes as $alias", function ($join) use ($alias, $value, $index, $name) {
                $join->on('products_variants.id', '=', "$alias.products_variants_id")
                     ->join('attributes as a' . $index, function ($join) use ($alias, $value, $name, $index) {
                         $join->on("$alias.attributes_id", '=', 'a' . $index . '.id')
                              ->where('a' . $index . '.name', '=', $name)
                              ->where("$alias.value", '=', $value);
                     });
            });

            $index++;
        }

        $query->join('products_variants_channels as pvc', 'products_variants.id', '=', 'pvc.products_variants_id')
              ->join('channels as c', 'pvc.channels_id', '=', 'c.id')
              ->where('c.uuid', '=', $channelId)
              ->where('products_variants.is_deleted', '=', 0)
              ->where('pvc.is_deleted', '=', 0)
              ->where('c.is_deleted', '=', 0);

        if ($priceRange) {
            $query->whereBetween('pvc.price', $priceRange);
        }

        return $query->select('products_variants.*')->distinct();
    }
}
