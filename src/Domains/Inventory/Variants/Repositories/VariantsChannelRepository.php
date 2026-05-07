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

        $channel = Channels::where('uuid', $channelId)
            ->where('is_deleted', 0)
            ->first(['id']);

        if (! $channel) {
            return Variants::query()->whereRaw('1=0');
        }

        $query = Variants::query()
            ->join('products_variants_channels as pvc', 'products_variants.id', '=', 'pvc.products_variants_id')
            ->where('pvc.channels_id', $channel->id)
            ->where('products_variants.is_deleted', 0)
            ->where('pvc.is_published', 1)
            ->where('pvc.is_deleted', 0);

        $attributeCount = count($attributes);
        if ($attributeCount > 0) {
            foreach ($attributes as $name => $value) {
                $query->whereExists(function ($subQuery) use ($name, $value) {
                    $subQuery->select(DB::raw(1))
                        ->from('products_variants_attributes as pva')
                        ->join('attributes as a', 'pva.attributes_id', '=', 'a.id')
                        ->whereRaw('pva.products_variants_id = products_variants.id')
                        ->where('a.name', $name)
                        ->where('a.is_deleted', 0)
                        ->where(function ($valueQuery) use ($value) {
                            if (is_numeric($value)) {
                                // For numeric values
                                $valueQuery
                                    // JSON format: {"en": 0} or {"en": 1}
                                    ->whereRaw(
                                        'JSON_VALID(pva.value) = 1 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(pva.value, \'$.en\')) AS SIGNED) = ?',
                                        [(int)$value]
                                    )
                                    // Legacy plain numeric: must be exactly the value
                                    // Use explicit comparison to avoid type coercion issues
                                    ->orWhere(function ($legacyQuery) use ($value) {
                                        $legacyQuery->whereRaw('JSON_VALID(pva.value) = 0')
                                                    ->where(function ($plainValueQuery) use ($value) {
                                                        // Check both as string and as cast number
                                                        $plainValueQuery->where('pva.value', (string)$value)
                                                                        ->orWhereRaw('CAST(pva.value AS SIGNED) = ?', [(int)$value]);
                                                    });
                                    });
                            } else {
                                // For string values
                                $valueQuery
                                    // JSON format: {"en": "value"}
                                    ->whereRaw(
                                        'JSON_VALID(pva.value) = 1 AND JSON_UNQUOTE(JSON_EXTRACT(pva.value, \'$.en\')) = ?',
                                        [$value]
                                    )
                                    // Legacy plain string
                                    ->orWhere(function ($legacyQuery) use ($value) {
                                        $legacyQuery->whereRaw('JSON_VALID(pva.value) = 0')
                                                    ->where('pva.value', $value);
                                    });
                            }
                        });
                });
            }
        }

        // Handle millage
        if ($millage !== null && is_array($millage) && count($millage) === 2) {
            $query->whereExists(function ($subQuery) use ($millage) {
                $subQuery->select(DB::raw(1))
                    ->from('products_variants_attributes as pva')
                    ->join('attributes as a', 'pva.attributes_id', '=', 'a.id')
                    ->whereRaw('pva.products_variants_id = products_variants.id')
                    ->where('a.name', 'odometer')
                    ->where('a.is_deleted', 0)
                    ->where(function ($rangeQuery) use ($millage) {
                        $rangeQuery
                            // JSON format
                            ->whereRaw(
                                'JSON_VALID(pva.value) = 1 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(pva.value, \'$.en\')) AS DECIMAL(10,2)) BETWEEN ? AND ?',
                                [(float)$millage[0], (float)$millage[1]]
                            )
                            // Legacy format
                            ->orWhere(function ($legacyQuery) use ($millage) {
                                $legacyQuery->whereRaw('JSON_VALID(pva.value) = 0')
                                            ->whereBetween(DB::raw('CAST(pva.value AS DECIMAL(10,2))'), [(float)$millage[0], (float)$millage[1]]);
                            });
                    });
            });
        }

        // Apply price range filtering
        if ($priceRange && count($priceRange) === 2) {
            $query->where(function ($priceQuery) use ($priceRange) {
                $priceQuery
                    // Legacy plain numeric price
                    ->where(function ($legacyPrice) use ($priceRange) {
                        $legacyPrice->whereRaw('JSON_VALID(pvc.price) = 0')
                                    ->whereBetween('pvc.price', $priceRange);
                    })
                    // JSON format price
                    ->orWhereRaw(
                        'JSON_VALID(pvc.price) = 1 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(pvc.price, \'$.en\')) AS DECIMAL(10,2)) BETWEEN ? AND ?',
                        [(float)$priceRange[0], (float)$priceRange[1]]
                    );
            });
        }

        return $query->select('products_variants.*');
    }
}
