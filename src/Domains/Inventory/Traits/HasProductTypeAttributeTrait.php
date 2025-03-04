<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

trait HasProductTypeAttributeTrait
{
    public function syncAttributesFromProductType(string $type = 'variant'): void
    {
        $id = $this->id;

        if ($type === 'variant') {
            $productTypeId = $this->product->products_types_id;
            $attributeModel = VariantsAttributes::class;
            $idColumn = 'products_variants_id';
            $toVariantValue = 1; // to_variant = 1 for variant attributes
        } else {
            $productTypeId = $this->products_types_id;
            $attributeModel = ProductsAttributes::class;
            $idColumn = 'products_id';
            $toVariantValue = 0; // to_variant = 0 for product attributes
        }

        // Get all attributes for this product type
        $typeAttributes = DB::connection('inventory')
            ->table('attributes as a')
            ->join('products_types_attributes as pta', function ($join) use ($productTypeId, $toVariantValue) {
                $join->on('a.id', '=', 'pta.attributes_id')
                    ->where('pta.products_types_id', '=', $productTypeId)
                    ->where('pta.to_variant', '=', $toVariantValue)
                    ->where('pta.is_deleted', '=', 0);
            })
            ->select('a.id')
            ->get()
            ->pluck('id')
            ->toArray();

        // Get existing attribute IDs
        $existingAttributeIds = $attributeModel::where($idColumn, $id)
            ->where('is_deleted', 0)
            ->pluck('attributes_id')
            ->toArray();

        // Find attributes to add
        $attributesToAdd = array_diff($typeAttributes, $existingAttributeIds);

        if (empty($attributesToAdd)) {
            return;
        }

        // Create records for new attributes
        foreach ($attributesToAdd as $attributeId) {
            $attribute = new $attributeModel();
            $attribute->$idColumn = $id;
            $attribute->attributes_id = $attributeId;
            $attribute->value = null;
            $attribute->save();
        }

        // Mark as deleted any attributes that no longer belong
        $attributesToRemove = array_diff($existingAttributeIds, $typeAttributes);
        if (! empty($attributesToRemove)) {
            $attributeModel::where($idColumn, $id)
                ->whereIn('attributes_id', $attributesToRemove)
                ->update(['is_deleted' => 1]);
        }
    }
}
