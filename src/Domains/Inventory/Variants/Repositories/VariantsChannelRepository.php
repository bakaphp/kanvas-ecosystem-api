<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Channels\Models\Channels;
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
        $millage = $attributes['millage'] ?? null;
        unset($attributes['millage']);
        unset($attributes['price']);

        // Use the Channels model to get the channel ID with the correct connection
        $channel = Channels::where('uuid', $channelId)
            ->where('is_deleted', 0)
            ->first(['id']);

        if (! $channel) {
            return Variants::query()->whereRaw('1=0'); // Empty result
        }

        $query = Variants::query()
            ->join('products_variants_channels as pvc', 'products_variants.id', '=', 'pvc.products_variants_id')
            ->where('pvc.channels_id', $channel->id)
            ->where('products_variants.is_deleted', 0)
            ->where('pvc.is_published', 1)
            ->where('pvc.is_deleted', 0);

        // Use EXISTS subqueries instead of multiple JOINs for better performance
        $attributeCount = count($attributes);
        if ($attributeCount > 0) {
            $query->whereExists(function ($subQuery) use ($attributes) {
                $subQuery->select(DB::raw(1))
                    ->from('products_variants_attributes as pva')
                    ->join('attributes as a', 'pva.attributes_id', '=', 'a.id')
                    ->whereRaw('pva.products_variants_id = products_variants.id')
                    ->where('a.is_deleted', 0)
                    ->where(function ($attrQuery) use ($attributes) {
                        foreach ($attributes as $name => $value) {
                            $attrQuery->orWhere(function ($singleAttr) use ($name, $value) {
                                $singleAttr->where('a.name', $name)
                                    ->where(function ($valueQuery) use ($value) {
                                        $valueQuery->where(function ($jsonQuery) use ($value) {
                                            $jsonQuery->whereRaw('JSON_VALID(pva.value) = 1')
                                                      ->whereRaw("JSON_EXTRACT(pva.value, '$.en') = ?", [$value]);
                                        })
                                        ->orWhere('pva.value', $value);
                                    });
                            });
                        }
                    })
                    ->groupBy('pva.products_variants_id')
                    ->havingRaw('COUNT(DISTINCT a.name) = ?', [$attributeCount]);
            });
        }

        // Handle millage with EXISTS subquery
        if ($millage !== null && is_array($millage) && count($millage) === 2) {
            $query->whereExists(function ($subQuery) use ($millage) {
                $subQuery->select(DB::raw(1))
                    ->from('products_variants_attributes as pva')
                    ->join('attributes as a', 'pva.attributes_id', '=', 'a.id')
                    ->whereRaw('pva.products_variants_id = products_variants.id')
                    ->where('a.name', 'odometer')
                    ->where('a.is_deleted', 0)
                    ->where(function ($rangeQuery) use ($millage) {
                        $rangeQuery->where(function ($jsonQuery) use ($millage) {
                            $jsonQuery->whereRaw('JSON_VALID(pva.value) = 1')
                                      ->whereRaw("CAST(JSON_EXTRACT(pva.value, '$.en') AS DECIMAL(10,2)) >= ?", [$millage[0]])
                                      ->whereRaw("CAST(JSON_EXTRACT(pva.value, '$.en') AS DECIMAL(10,2)) <= ?", [$millage[1]]);
                        })
                        ->orWhere(function ($nonJsonQuery) use ($millage) {
                            $nonJsonQuery->where('pva.value', '>=', $millage[0])
                                         ->where('pva.value', '<=', $millage[1]);
                        });
                    });
            });
        }

        // Apply price range filtering
        if ($priceRange && count($priceRange) === 2) {
            $query->where(function ($priceQuery) use ($priceRange) {
                $priceQuery->whereBetween('pvc.price', $priceRange)
                    ->orWhere(function ($jsonPriceQuery) use ($priceRange) {
                        $jsonPriceQuery->whereRaw('JSON_VALID(pvc.price) = 1')
                                      ->whereRaw("CAST(JSON_EXTRACT(pvc.price, '$.en') AS DECIMAL(10,2)) >= ?", [$priceRange[0]])
                                      ->whereRaw("CAST(JSON_EXTRACT(pvc.price, '$.en') AS DECIMAL(10,2)) <= ?", [$priceRange[1]]);
                    });
            });
        }

        return $query->select('products_variants.*');
    }
}
