<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Actions;

use Baka\Support\Str;
use Carbon\Carbon;
use Kanvas\Inventory\Products\Actions\CreateProductAction as BaseCreateProductAction;
use Kanvas\Inventory\Products\Models\Products;
use Override;

class CreateProductAction extends BaseCreateProductAction
{
    #[Override]
    public function execute(): Products
    {
        $productType = $this->productDto?->productsType?->getId();

        $search = [
            'slug' => $this->productDto->slug ?? Str::slug($this->productDto->name),
            'apps_id' => $this->productDto->app->getId(),
            'companies_id' => $this->productDto->company->getId(),
        ];

        $updateData = [
            'products_types_id' => $productType,
            'name' => $this->productDto->name,
            'description' => $this->productDto->getDescription(),
            'short_description' => $this->productDto->short_description,
            'html_description' => ! empty($this->productDto->html_description) ? $this->productDto->html_description : $this->productDto->getDescription(),
            'warranty_terms' => $this->productDto->warranty_terms,
            'upc' => $this->productDto->upc,
            'status_id' => $this->productDto->status_id,
            'users_id' => $this->user->getId(),
            'is_published' => $this->productDto->is_published,
            'published_at' => Carbon::now(),
            'weight' => $this->productDto->weight ?? $existingProduct?->weight ?? 0,
        ];

        $existingProduct = Products::where($search)->lockForUpdate()->first();

        if ($existingProduct) {
            $existingProduct->update($updateData);
            $products = $existingProduct;
        } else {
            $products = Products::create(array_merge($search, $updateData));
        }

        return $products;
    }
}
