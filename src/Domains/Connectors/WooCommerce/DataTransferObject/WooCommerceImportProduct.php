<?php
declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\DataTransferObject;

use Spatie\LaravelData\Data;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Illuminate\Support\Str;
use Kanvas\Connectors\WooCommerce\Services\WooCommerce;

class WooCommerceImportProduct extends ProductImporter
{
    public static function fromWooCommerce(object $product): self
    {
        $variants = [];
        $categories = [];
        if (isset($product->variations)) {
            foreach ($product->variations as $variant) {
                $variants[] = [
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'quantity' => (int)$variant->stock_quantity,
                    'source_id' => (string)$variant->id,
                ];
            }
        }
        foreach ($product->categories as $key => $category) {
            $categories[] = [
                'name' => $category->name,
                'slug' => $category->slug,
                'source_id' => null,
                'code' => null,
                'isPublished' => true,
                'position' => $key + 1,
            ];
        }
        $files = [];
        foreach ($product->images as $image) {
            $files[] = [
                'name' => $image->name,
                'url' => $image->src,
            ];
        }
        $attributes = [];
        foreach ($product->attributes as $attribute) {
            $attributes[] = [
                'name' => $attribute->name,
                'value' => $attribute->options[0],
            ];
        }
        return new self(
            name: $product->name,
            slug: $product->slug,
            sku: $product->sku,
            price: (float)$product->price,
            discountPrice: (int)$product->sale_price,
            isPublished: $product->status == "publish",
            sourceId: (string)$product->id,
            variants: $variants,
            quantity: (int)$product->stock_quantity,
            description: $product->description,
            categories: $categories,
            position: 0,
            shortDescription: '',
            htmlDescription: '',
            warrantyTerms: '',
            upc: '',
            source: 'woocommerce',
            status: $product->status,
            files: $files,
            productType: [],
            attributes: $attributes,
            customFields: [],
            warehouses: []
        );
    }
}
