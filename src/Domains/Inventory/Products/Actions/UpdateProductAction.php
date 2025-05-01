<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Categories\Repositories\CategoriesRepository;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class UpdateProductAction
{
    protected bool $runWorkflow = true;

    public function __construct(
        protected Products $product,
        protected ProductDto $productDto,
        protected UserInterface $user,
    ) {
    }

    public function execute(): Products
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->productDto->company,
            $this->user
        );

        try {
            DB::connection('inventory')->beginTransaction();

            $productType = $this->productDto?->productsType?->getId();

            $this->product->update(
                [
                    'products_types_id' => $productType,
                    'name' => $this->productDto->name,
                    'slug' => $this->productDto->slug ?? $this->product->slug,
                    'description' => $this->productDto->getDescription() ?? $this->product->description,
                    'short_description' => $this->productDto->short_description ?? $this->product->short_description,
                    'html_description' => $this->productDto->html_description ?? $this->product->html_description,
                    'warranty_terms' => $this->productDto->warranty_terms ?? $this->product->warranty_terms,
                    'upc' => $this->productDto->upc ?? $this->product->upc,
                    'status_id' => $this->productDto->status_id ?? $this->product->status_id,
                    'is_published' => $this->productDto->is_published,
                    'published_at' => $this->productDto->is_published ? Carbon::now() : null,
                    'weight' => $this->productDto->weight ?? $this->product->weight ?? 0,
                ]
            );

            if (! empty($this->productDto->files)) {
                $this->product->addMultipleFilesFromUrl($this->productDto->files);
            }

            if (! empty($this->productDto->categories)) {
                $this->product->productsCategories()->forceDelete();

                foreach ($this->productDto->categories as $category) {
                    (new AddCategoryAction(
                        $this->product,
                        CategoriesRepository::getById((int) $category['id'], $this->product->company)
                    ))->execute();
                }
            }

            if ($this->productDto->attributes || empty($this->productDto->attributes)) {
                $this->product->attributeValues()->forceDelete();
                $this->product->addAttributes($this->user, $this->productDto->attributes);
            }

            DB::connection('inventory')->commit();
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        if ($this->product->isPublished()) {
            $this->product->searchable();
        } else {
            $this->product->unsearchable();
        }

        if ($this->runWorkflow) {
            $this->product->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true
            );
        }

        return $this->product;
    }
}
