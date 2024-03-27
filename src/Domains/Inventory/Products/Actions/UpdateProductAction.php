<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

class UpdateProductAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected Products $product,
        protected ProductDto $productDto,
        protected UserInterface $user,
    ) {
    }

    /**
     * execute.
     */
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
                    'description' => $this->productDto->description,
                    'short_description' => $this->productDto->short_description,
                    'html_description' => $this->productDto->html_description,
                    'warranty_terms' => $this->productDto->warranty_terms,
                    'upc' => $this->productDto->upc,
                    'is_published' => $this->productDto->is_published,
                    'published_at' => $this->productDto->is_published ? Carbon::now() : null,
                ]
            );
           
            if (! empty($this->productDto->files)) {
                $this->product->addMultipleFilesFromUrl($this->productDto->files);
            }

            DB::connection('inventory')->commit();
        } catch (Throwable $e) {
            DB::connection('inventory')->rollback();

            throw $e;
        }

        return $this->product;
    }
}
