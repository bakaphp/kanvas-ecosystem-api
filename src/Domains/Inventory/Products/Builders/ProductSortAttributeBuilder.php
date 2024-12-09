<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Products\Models\Products;

class ProductSortAttributeBuilder
{
    public array $orderValue = [
        'STRING' => "
            CASE 
                WHEN pva.value IS NOT NULL THEN pva.value
                ELSE ''
            END 
        ",
        'NUMERIC' => "
            CASE 
                WHEN pva.value REGEXP '^[0-9]+$' THEN CAST(pva.value AS UNSIGNED)
                ELSE NULL
            END",
        'DATE' => "
            CASE 
                WHEN STR_TO_DATE(value, '%Y-%m-%d') IS NOT NULL THEN STR_TO_DATE(value, '%Y-%m-%d')
                ELSE NULL
            END 
        ",
    ];
    public string $caseAttribute = '
        CASE     
            WHEN a.name = ? THEN a.name
            ELSE NULL
        END 
    ';

    public static function sortProductByAttribute(
        Builder $query,
        string $name,
        string $format = 'STRING',
        string $sort = 'asc'
    ): Builder {
        $self = new self();
        $order = $self->orderValue[$format];
        $orderRaw = $order . ' ' . $sort . ' ,' . $self->caseAttribute . ' ' . $sort . ', products.id ASC';
        $subquery = $query->join('products_attributes as pva', 'pva.products_id', '=', 'products.id')
            ->leftJoin('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->orderByRaw(
                $orderRaw,
                [$name]
            )
            ->select('products.*');

        return Products::query()
                ->fromSub($subquery, 'products')
                ->groupBy('products.id')
                ->select('products.*');
    }

    public static function sortProductByVariantAttribute(
        Builder $query,
        string $name,
        string $format = 'STRING',
        string $sort = 'asc'
    ): Builder {
        $self = new self();
        $order = $self->orderValue[$format];
        $orderRaw = $order . ' ' . $sort . ' ,' . $self->caseAttribute . ' ' . $sort . ', products.id ASC';
        $subquery = $query->join('products_variants as variants', 'variants.products_id', '=', 'products.id')
            ->join('products_variants_attributes as pva', 'pva.products_variants_id', '=', 'variants.id')
            ->leftJoin('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->orderByRaw(
                $orderRaw,
                [$name]
            )
            ->select('products.*');

        return Products::query()
                ->fromSub($subquery, 'products')
                ->groupBy('products.id')
                ->select('products.*');
    }
}
