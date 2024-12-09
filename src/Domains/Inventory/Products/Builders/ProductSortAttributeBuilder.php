<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Builders;

use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Products\Models\Products;

class ProductSortAttributeBuilder
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;

    public array $castValue = [
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

        $attributeName = Products::query()
            ->from('products as subProductAttributeName')
            ->join('products_attributes as pva', 'pva.products_id', '=', 'subProductAttributeName.id')
            ->join('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->whereColumn('subProductAttributeName.id', 'products.id')
            ->selectRaw("'{$name}' as attribute_name")
            ->limit(1);
        $attributeValue = Products::query()
                    ->from('products as subProductAttributeName')

            ->join('products_attributes as pva', 'pva.products_id', '=', 'subProductAttributeName.id')
            ->join('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->whereColumn('subProductAttributeName.id', 'products.id')
            ->selectRaw($self->castValue[$format] . ' as attribute_value')
            ->limit(1);

        $query->addSelect([
            'attribute_name' => $attributeName,
            'attribute_value' => $attributeValue,
        ]);
        $query->orderBy('attribute_name', 'ASC');
        $query->orderBy('attribute_value', $sort);

        return $query;
    }

    public static function sortProductByVariantAttribute(
        Builder $query,
        string $name,
        string $format = 'STRING',
        string $sort = 'asc'
    ): Builder {
        $self = new self();

        $attributeName = Products::query()
            ->from('products as subProductAttributeName')
            ->join('products_variants as pv', 'subProductAttributeName.id', 'pv.products_id')
            ->join('products_variants_attributes as pva', 'pva.products_variants_id', '=', 'pv.id')
            ->join('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->whereColumn('subProductAttributeName.id', 'products.id')
            ->selectRaw("'{$name}' as attribute_name")
            ->limit(1);
        $attributeValue = Products::query()
            ->from('products as subProductAttributeName')
            ->join('products_variants as pv', 'subProductAttributeName.id', 'pv.products_id')
            ->join('products_variants_attributes as pva', 'pva.products_variants_id', '=', 'pv.id')
            ->join('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->whereColumn('subProductAttributeName.id', 'products.id')
            ->selectRaw($self->castValue[$format] . ' as attribute_value')
            ->limit(1);

        $query->addSelect([
            'attribute_name' => $attributeName,
            'attribute_value' => $attributeValue,
        ]);
        $query->orderBy('attribute_name', 'ASC');
        $query->orderBy('attribute_value', $sort);

        $query->addSelect([
            'attribute_name' => $attributeName,
            'attribute_value' => $attributeValue,
        ]);
        $query->orderBy('attribute_name', 'ASC');
        $query->orderBy('attribute_value', $sort);

        return $query;
    }
}
